<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\SearchKitBundle\Contract\FilterableSearchProviderInterface;
use Nowo\SearchKitBundle\Contract\IdentifierAwareSearchProviderInterface;
use Nowo\SearchKitBundle\Contract\MinLengthExemptSearchProviderInterface;
use Nowo\SearchKitBundle\Contract\PaginatedSearchProviderInterface;
use Nowo\SearchKitBundle\Contract\SearchEngineInterface;
use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Model\SearchAccessRequest;
use Nowo\SearchKitBundle\Model\SearchContext;
use Nowo\SearchKitBundle\Model\SearchFilter;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Search\Access\SearchAccess;
use Nowo\SearchKitBundle\Search\Action\ActionCollector;
use Nowo\SearchKitBundle\Search\Filter\SearchFilterParser;
use Nowo\SearchKitBundle\Search\Group\SearchGroupSettings;
use Nowo\SearchKitBundle\Search\Ranking\SearchRanker;
use Nowo\SearchKitBundle\Search\Synonym\SynonymExpander;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function array_key_exists;
use function count;
use function in_array;
use function strlen;

/**
 * Runs every tagged SearchProviderInterface (priority order) and keeps the non-empty groups.
 *
 * A provider that throws is logged and skipped: one broken group never takes the whole search box down, and never
 * shows an error row. The log entry names the provider and the exception class only (never the query text, which may
 * be personal data). Stateless: safe in FrankenPHP worker mode.
 *
 * 1.8.0 pipeline (see docs/SECURITY.md, "Anti-leak model"):
 *
 * 1. the `>` prefix (`actions.prefix`) asks for actions only; otherwise the typed filters are parsed out of the text
 *    (`filters.keys`) and the synonyms expanded (`synonyms`);
 * 2. the Actions group (SearchActionProviderInterface, access-checked) comes first;
 * 3. a provider runs only when it supports every filter key of the query (deny by default; `type` is the engine's);
 * 4. with `access.filter_all_rows`, every row must pass the SearchAccessCheckerInterface **before** anything else;
 * 5. a total is capped to the visible rows unless the provider marks it exact (SearchGroup::$exactTotal);
 * 6. groups with the same key are merged (deduplicated by URL), `type:` filters keep or drop groups, rows are sorted
 *    by relevance + recency (SearchRanker) and cut to the row limit.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchEngine implements SearchEngineInterface
{
    /** Built-in filter key handled by the engine: keeps (or, negated, drops) the groups of that key. */
    public const string TYPE_FILTER = 'type';

    /**
     * @param iterable<SearchProviderInterface> $providers Tagged providers, highest priority first
     * @param int $minLength Shorter (trimmed) queries return no groups
     * @param int $perGroup Rows per group
     * @param LoggerInterface|null $logger Failing providers are reported here
     * @param list<string> $minLengthExemptPatterns A shorter query matching one runs, on the providers implementing MinLengthExemptSearchProviderInterface only
     * @param SearchFilterParser|null $filters Typed filter parser (1.8.0), or null: no filters
     * @param SynonymExpander|null $synonyms Synonym expansion (1.8.0), or null: none
     * @param ActionCollector|null $actions Palette actions (1.8.0), or null: no Actions group, no `>` prefix
     * @param SearchAccess|null $access Access checkers, used with `access.filter_all_rows` (1.8.0)
     * @param SearchRanker|null $ranker Relevance + recency sort (1.8.0), or null: provider order
     * @param array{fuzzy?: bool, filter_all_rows?: bool, action_prefix?: string, actions_in_results?: bool} $options 1.8.0 switches
     * @param SearchGroupSettings|null $groups `nowo_search_kit.groups` (1.9.0): groups switched off, group priorities; null: none
     *
     * @throws InvalidArgumentException When a pattern is invalid (container compilation reports it first)
     */
    public function __construct(
        private iterable $providers,
        private int $minLength = 2,
        private int $perGroup = 5,
        private ?LoggerInterface $logger = null,
        private array $minLengthExemptPatterns = [],
        private ?SearchFilterParser $filters = null,
        private ?SynonymExpander $synonyms = null,
        private ?ActionCollector $actions = null,
        private ?SearchAccess $access = null,
        private ?SearchRanker $ranker = null,
        private array $options = [],
        private ?SearchGroupSettings $groups = null,
    ) {
        foreach ($minLengthExemptPatterns as $pattern) {
            $problem = MinLengthExemptPattern::problem($pattern);
            if ($problem !== null) {
                throw new InvalidArgumentException('Invalid min_length exempt pattern: ' . $problem . '.');
            }
        }
    }

    /**
     * Searches every provider for a Symfony user: the providers get both SearchQuery::$userIdentifier and the
     * deprecated SearchQuery::$user. The bundle's endpoint uses it so that 1.x providers keep working.
     *
     * @param UserInterface $user The signed-in user
     * @param string $text Raw query (trimmed here)
     * @param string|null $scope Scope id, or null
     * @param string|null $locale UI locale (1.8.0), or null
     * @param SearchContext|null $context The page the box is on, for the action providers (1.8.0), or null
     *
     * @return list<SearchGroup> Non-empty groups, in provider order (Actions first), each with at most `per_group` rows
     */
    public function search(UserInterface $user, string $text, ?string $scope = null, ?string $locale = null, ?SearchContext $context = null): array
    {
        return $this->execute(new SearchRequest($text, $user, null, $scope, $locale, $context))->groups;
    }

    /**
     * Searches by user identifier: only the IdentifierAwareSearchProviderInterface providers run; every other one is
     * skipped and logged (notice), since it may depend on a Symfony user that does not exist here.
     *
     * @param string|null $locale UI locale (1.8.0), or null
     *
     * @throws InvalidArgumentException When the identifier is empty
     */
    public function searchFor(string $userIdentifier, string $text, ?string $scope = null, ?string $locale = null): array
    {
        if ($userIdentifier === '') {
            throw new InvalidArgumentException('A search by identifier needs a non-empty user identifier.');
        }

        return $this->execute(new SearchRequest($text, null, $userIdentifier, $scope, $locale))->groups;
    }

    /**
     * Runs one search and returns the groups with the parsed filters (1.8.0; the results page uses it).
     *
     * @param SearchRequest $request The text, the viewer and the page
     *
     * @return SearchResult The visible groups
     */
    public function execute(SearchRequest $request): SearchResult
    {
        $prepared = $this->prepare($request);
        if ($prepared === null) {
            return new SearchResult();
        }
        [$query, $actionsOnly] = $prepared;

        $groups = [];
        $action = null;
        if ($this->actions instanceof ActionCollector && ($actionsOnly || $this->actionsInResults($this->actions, $query))) {
            $action = $this->actionGroup($this->actions, $query, $request);
            if ($action instanceof SearchGroup) {
                $groups[] = $action;
            }
        }
        if (!$actionsOnly) {
            foreach ($this->providers as $provider) {
                if (!$this->switchedOn($provider) || !$this->runs($provider, $query, $request)) {
                    continue;
                }
                $group = $this->call($provider, static fn (): ?SearchGroup => $provider->search($query));
                if ($group instanceof SearchGroup && $group->items !== []) {
                    $groups[] = $group;
                }
            }
        }

        return new SearchResult($this->finalise($groups, $query, $action), $query->filters, $query, $actionsOnly);
    }

    /**
     * One page of a group whose provider implements PaginatedSearchProviderInterface (1.8.0, results page).
     *
     * @param SearchRequest $request The text, the viewer and the page (its limit is ignored)
     * @param string $groupKey Key of the group to page
     * @param int $page Page number (from 1)
     * @param int $perPage Rows per page
     *
     * @return SearchGroup|null The visible rows of that page, or null (no paginated provider for the key, filtered
     *                          out, empty page or failing provider)
     */
    public function page(SearchRequest $request, string $groupKey, int $page, int $perPage): ?SearchGroup
    {
        $prepared = $this->prepare(new SearchRequest($request->text, $request->user, $request->userIdentifier, $request->scope, $request->locale, $request->context, $perPage));
        if ($prepared === null || $prepared[1]) {
            return null;
        }
        $query = $prepared[0];
        foreach ($this->providers as $provider) {
            if (!$provider instanceof PaginatedSearchProviderInterface || $provider->groupKey() !== $groupKey || !$this->switchedOn($provider) || !$this->runs($provider, $query, $request)) {
                continue;
            }
            $group = $this->call($provider, static fn (): ?SearchGroup => $provider->searchPage($query, max(1, $page), $perPage));

            return $group instanceof SearchGroup ? ($this->finalise([$group], $query, null)[0] ?? null) : null;
        }

        return null;
    }

    /**
     * Whether a paginated provider exists for a group key.
     *
     * @param string $groupKey The key
     *
     * @return bool True when the results page can page that group
     */
    public function isPaginated(string $groupKey): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof PaginatedSearchProviderInterface && $provider->groupKey() === $groupKey && $this->switchedOn($provider)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The query the providers get, and whether it is a `>` (actions only) query; null when nothing runs.
     *
     * @return array{0: SearchQuery, 1: bool}|null
     */
    private function prepare(SearchRequest $request): ?array
    {
        $text   = trim($request->text);
        $prefix = (string) ($this->options['action_prefix'] ?? '>');
        $limit  = $request->limit ?? $this->perGroup;

        if ($this->actions instanceof ActionCollector && $prefix !== '' && str_starts_with($text, $prefix)) {
            $rest = trim(substr($text, strlen($prefix)));

            return [new SearchQuery($rest, $request->user, $limit, $request->scope, false, $request->userIdentifier, [], $request->locale, [], (bool) ($this->options['fuzzy'] ?? false)), true];
        }

        [$text, $filters] = $this->filters instanceof SearchFilterParser ? $this->filters->parse($text) : [$text, []];
        $short            = mb_strlen($text) < $this->minLength;
        if ($short && ($filters === [] || $text !== '') && ($filters !== [] || !$this->isExempt($text))) {
            return null;
        }
        $exempt = $short && $filters === [];
        $terms  = $this->synonyms instanceof SynonymExpander && !$exempt ? $this->synonyms->expand($text, $request->locale) : [];

        return [new SearchQuery($text, $request->user, $limit, $request->scope, $exempt, $request->userIdentifier, $filters, $request->locale, $terms, (bool) ($this->options['fuzzy'] ?? false)), false];
    }

    /**
     * Whether a provider runs for a query (min length exemption, identifier search, filter support).
     */
    private function runs(SearchProviderInterface $provider, SearchQuery $query, SearchRequest $request): bool
    {
        // The minimum exists so that providers do not scan for a fragment: only those that asked get one.
        if ($query->belowMinLength && !$provider instanceof MinLengthExemptSearchProviderInterface) {
            return false;
        }
        // By identifier, only the providers that declared they need nothing else (no Symfony user) run.
        if (!$request->user instanceof UserInterface && !$provider instanceof IdentifierAwareSearchProviderInterface) {
            $this->logger?->notice('Search provider {provider} skipped in a search by identifier: implement {interface} (the Symfony user is deprecated since nowo-tech/search-kit-bundle 1.7.0).', [
                'provider'  => $provider::class,
                'interface' => IdentifierAwareSearchProviderInterface::class,
            ]);

            return false;
        }

        // A filters-only query (no text) reaches only the providers that understand filters: a 1.x provider never
        // got an empty text and might list everything.
        if ($query->text === '' && !$provider instanceof FilterableSearchProviderInterface) {
            return false;
        }
        // Deny by default: a provider that does not apply a filter would return rows the viewer filtered out.
        foreach ($query->filters as $filter) {
            if ($filter->key !== self::TYPE_FILTER
                && (!$provider instanceof FilterableSearchProviderInterface || !in_array($filter->key, $provider->supportedFilters(), true))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a provider whose group key is known is switched on (`nowo_search_kit.groups.<key>.enabled`, 1.9.0).
     */
    private function switchedOn(SearchProviderInterface $provider): bool
    {
        return !$this->groups instanceof SearchGroupSettings
            || !$provider instanceof PaginatedSearchProviderInterface
            || $this->groups->isEnabled($provider->groupKey());
    }

    /**
     * Calls a provider; an exception drops its group (logged with the class names only).
     *
     * @param callable(): ?SearchGroup $call
     */
    private function call(SearchProviderInterface $provider, callable $call): ?SearchGroup
    {
        try {
            return $call();
        } catch (Throwable $exception) {
            $this->logger?->error('Search provider {provider} failed: {exception}.', [
                'provider'  => $provider::class,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    /**
     * Whether the Actions group goes on top of a normal query: providers registered, no filter, a real query.
     */
    private function actionsInResults(ActionCollector $actions, SearchQuery $query): bool
    {
        return ($this->options['actions_in_results'] ?? true)
            && !$query->belowMinLength
            && !$query->hasFilters()
            && $actions->hasProviders();
    }

    private function actionGroup(ActionCollector $actions, SearchQuery $query, SearchRequest $request): ?SearchGroup
    {
        $context = $request->context ?? new SearchContext($request->userIdentifier, '', null, [], $request->locale, $request->user instanceof UserInterface ? array_values($request->user->getRoles()) : []);
        try {
            return $actions->search($query, $context, $request->user instanceof UserInterface, $query->limit);
        } catch (Throwable $exception) {
            $this->logger?->error('Search actions failed: {exception}.', ['exception' => $exception::class]);

            return null;
        }
    }

    /**
     * Access filter, total cap, merge by key, `type:` filters, ranking and the row limit.
     *
     * @param list<SearchGroup> $groups
     *
     * @return list<SearchGroup>
     */
    private function finalise(array $groups, SearchQuery $query, ?SearchGroup $action): array
    {
        $now    = new DateTimeImmutable();
        $merged = [];
        foreach ($groups as $group) {
            $group = $this->visible($group, $query, $group === $action);
            if ($group->items === [] || ($this->groups instanceof SearchGroupSettings && !$this->groups->isEnabled($group->key))) {
                continue;
            }
            $key          = $group->key;
            $merged[$key] = array_key_exists($key, $merged) ? $this->merge($merged[$key], $group) : $group;
        }

        $result = [];
        foreach ($merged as $key => $group) {
            if (!$this->typeAllows($key, $query->filtersOf(self::TYPE_FILTER))) {
                continue;
            }
            $group    = $this->ranker instanceof SearchRanker ? $this->ranker->sort($group, $now) : $group;
            $result[] = $group->limit($query->limit);
        }

        return $this->groups instanceof SearchGroupSettings ? $this->groups->sort($result, ActionCollector::KEY) : $result;
    }

    /**
     * The rows the viewer may open (with filter_all_rows) and a total that never exceeds them unless exact.
     */
    private function visible(SearchGroup $group, SearchQuery $query, bool $checked): SearchGroup
    {
        $items = $group->items;
        $exact = $group->exactTotal;
        if (!$checked && ($this->options['filter_all_rows'] ?? false)) {
            $kept = array_values(array_filter($items, fn (SearchItem $item): bool => $this->access instanceof SearchAccess
                && $this->access->allowsUrl($item->url, $query->userIdentifier, SearchAccessRequest::KIND_ROW, $query->scope)));
            // A row removed here was counted by the provider: its total is no longer exact.
            $exact = $exact && count($kept) === count($items);
            $items = $kept;
        }
        $visible = count($items);
        $total   = $exact ? max($visible, $group->total ?? $visible) : min($visible, $group->total ?? $visible);

        return $group->withItems($items, $total, $exact);
    }

    /**
     * Two groups with the same key (e.g. menu pages and registry pages): rows deduplicated by URL, first one wins.
     */
    private function merge(SearchGroup $first, SearchGroup $second): SearchGroup
    {
        $items = $first->items;
        $urls  = array_flip(array_map(static fn (SearchItem $item): string => $item->url, $items));
        $dupes = 0;
        foreach ($second->items as $item) {
            if (array_key_exists($item->url, $urls)) {
                ++$dupes;

                continue;
            }
            $urls[$item->url] = true;
            $items[]          = $item;
        }
        $exact = $first->exactTotal && $second->exactTotal;
        $total = $exact ? (int) $first->total + (int) $second->total - $dupes : count($items);

        return $first->withItems($items, max(count($items), $total), $exact);
    }

    /**
     * @param list<SearchFilter> $types
     */
    private function typeAllows(string $key, array $types): bool
    {
        $wanted = [];
        foreach ($types as $type) {
            $value = strtolower($type->value);
            if ($type->negated && $value === $key) {
                return false;
            }
            if (!$type->negated) {
                $wanted[] = $value;
            }
        }

        return $wanted === [] || in_array($key, $wanted, true);
    }

    /**
     * Whether a query below `min_length` matches an exempt pattern. Never the empty query, whatever the pattern.
     */
    private function isExempt(string $text): bool
    {
        if ($text === '') {
            return false;
        }
        foreach ($this->minLengthExemptPatterns as $pattern) {
            $matches = MinLengthExemptPattern::matches($pattern, $text);
            if ($matches === true) {
                return true;
            }
            // Backtrack or JIT limit: the query stays refused, and the host learns its pattern is too costly.
            if ($matches === null) {
                $this->logger?->error('Search min_length exempt pattern {pattern} failed: {error}.', [
                    'pattern' => $pattern,
                    'error'   => preg_last_error_msg(),
                ]);
            }
        }

        return false;
    }
}
