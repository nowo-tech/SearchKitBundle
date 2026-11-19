<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Provider\Entity;

use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use InvalidArgumentException;
use LogicException;
use Nowo\SearchKitBundle\Contract\PaginatedSearchProviderInterface;
use Nowo\SearchKitBundle\Contract\SearchReferenceResolverInterface;
use Nowo\SearchKitBundle\Event\SearchItemVisibilityEvent;
use Nowo\SearchKitBundle\Event\SearchKitEvents;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Model\SearchSubject;
use Nowo\SearchKitBundle\NowoSearchKitBundle;
use Nowo\SearchKitBundle\Search\Group\SearchGroupSettings;
use Nowo\SearchKitBundle\Search\Group\SensitiveGroupNotice;
use Nowo\SearchKitBundle\Search\Query\LikePattern;
use Nowo\SearchKitBundle\Search\Query\ScopeParser;
use Nowo\SearchKitBundle\Search\Query\SearchWindow;
use Nowo\SearchKitBundle\Search\Query\SearchWords;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function array_is_list;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function is_array;
use function mb_strlen;
use function preg_match;
use function str_starts_with;
use function trim;

/**
 * The one search flow of every entity provider (1.9.0), driven by an EntitySearchDefinition. Per call, in this order:
 *
 * 1. the group is on (`nowo_search_kit.groups.<key>.enabled`), and a sensitive group only runs while recents are
 *    revalidated or off;
 * 2. a non-empty text of at least the definition's minLength(), a page inside the candidate window;
 * 3. the viewer's tenant (null: no results), then the feature gate; then the definition's candidateQuery() with a new
 *    SearchCandidateContext (1.11.0; default baseQuery()), which filterCandidates() gets too;
 * 4. the scope, client input: shape-checked (ScopeParser), then applied by the definition in SQL — or refused;
 * 5. the definition's full-text hook, or an escaped LIKE (`ESCAPE '!'`) of every query word over the declared fields;
 * 6. at most `maxCandidates()` rows in one query;
 * 7. the bundle's access check on the candidates (batched: filterCandidates(), default filterVisible()), then **one** SearchKitEvents::ITEM_VISIBILITY
 *    event for the page, where a host deny wins;
 * 8. the page cut out of the visible rows, the visible total within the window (never counting rows the viewer may
 *    not see; exact only when the SQL fetch did not fill the window, whoever the rows were visible to — rows hidden
 *    only in PHP can still push visible ones out of the window — and the definition did not mark its candidates
 *    partial, 1.11.0), the "see all" link;
 * 9. the shown rows rendered: **one** prepareItems() call with exactly those rows and a new SearchItemContext, then
 *    toPreparedItem() per row (no call when no row is shown).
 *
 * Any refusal is `null`: no group, no count, no hint, so a forbidden or hidden row looks exactly like a missing one.
 * An exception from a hook reaches SearchKit, which logs the class names and drops the group (null as well).
 *
 * Also a SearchReferenceResolverInterface: with `recent.revalidate`, a stored recent is read again through the same
 * checks (EntitySearchDefinition::idField() / referenceId()), and rendered the same way (prepareItems() with that one row).
 *
 * Holds only its collaborators: stateless, safe in FrankenPHP worker mode with kernel reset off.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class EntitySearchProvider implements PaginatedSearchProviderInterface, SearchReferenceResolverInterface
{
    /** Allowed group keys: a letter first, so a key is always a valid SearchSubject type too. */
    public const string GROUP_PATTERN = '/^[a-z][a-z0-9_-]{0,63}\z/';

    /** Prefix of the parameters SearchKit binds on the definition's query. */
    public const string PARAMETER_PREFIX = 'nowo_search_';

    /**
     * @param EntitySearchDefinition $definition The bundle's hooks
     * @param EventDispatcherInterface|null $dispatcher Dispatches SearchKitEvents::ITEM_VISIBILITY (null: no host event)
     * @param TranslatorInterface|null $translator Translates the "see all" label (null: English)
     * @param SearchGroupSettings $groups `nowo_search_kit.groups`
     * @param bool $recentsSafe False while recents are kept in the browser without revalidation
     * @param SensitiveGroupNotice|null $notice Says once per container why a sensitive group is off (null: silent)
     */
    public function __construct(
        private EntitySearchDefinition $definition,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?TranslatorInterface $translator = null,
        private SearchGroupSettings $groups = new SearchGroupSettings(),
        private bool $recentsSafe = true,
        private ?SensitiveGroupNotice $notice = null,
    ) {
    }

    /**
     * The definition's group key.
     */
    public function groupKey(): string
    {
        return $this->definition->group();
    }

    /**
     * The group key, checked.
     *
     * @throws InvalidArgumentException When the key is not GROUP_PATTERN (SearchKit logs it and drops the group)
     */
    private function checkedGroup(): string
    {
        $key = $this->definition->group();
        if (preg_match(self::GROUP_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('An entity search group key must be a lower-case letter followed by up to 63 characters of a-z, 0-9, "_" or "-".');
        }

        return $key;
    }

    /**
     * The first rows (palette): page 1 of `$query->limit` rows.
     */
    public function search(SearchQuery $query): ?SearchGroup
    {
        return $this->searchPage($query, 1, $query->limit);
    }

    /**
     * One page of rows (results page).
     */
    public function searchPage(SearchQuery $query, int $page, int $perPage): ?SearchGroup
    {
        $group = $this->checkedGroup();
        if (!$this->runs($group)) {
            return null;
        }
        $text   = trim($query->text);
        $window = SearchWindow::of($page, $perPage, $this->definition->maxCandidates());
        if ($text === '' || mb_strlen($text) < $this->definition->minLength() || !$window instanceof SearchWindow) {
            return null;
        }
        $tenant = $this->definition->tenant($query);
        if ($tenant === null || !$this->definition->featureEnabled($tenant, $query)) {
            return null;
        }
        $context      = new SearchCandidateContext();
        $queryBuilder = $this->definition->candidateQuery($tenant, $query, $context);
        if ($query->scope !== null
            && (!ScopeParser::isValid($query->scope) || !$this->definition->applyScope($queryBuilder, $query->scope, $tenant, $query))) {
            return null;
        }
        $this->match($queryBuilder, $text, $query);

        $candidates = $window->candidatesOf($this->fetch($queryBuilder, $window->fetchLimit()));
        $visible    = $this->visible($candidates, $tenant, $query, $group, $context);
        $shown      = $window->slice($visible);
        if ($shown === []) {
            return null;
        }
        $total = $window->total($visible);

        return new SearchGroup(
            $group,
            $this->definition->label($query),
            $this->items($shown, $query),
            $total,
            $this->definition->seeAllUrl($query),
            $this->seeAllLabel($query, $total),
            !$context->isPartial() && $window->isExact($visible, count($candidates)),
        );
    }

    /**
     * A stored recent of this group, read again through every check; null unless it is still visible with the same
     * URL and kind.
     */
    public function resolve(SearchReference $reference, string $userIdentifier, ?string $locale): ?SearchItem
    {
        $group = $this->checkedGroup();
        $field = $this->definition->idField();
        if (!$this->runs($group) || $field === null || !LikePattern::isField($field)) {
            return null;
        }
        $id = $this->definition->referenceId($reference);
        if ($id === null) {
            return null;
        }
        $query  = new SearchQuery('', null, 1, null, false, $userIdentifier, [], $locale);
        $tenant = $this->definition->tenant($query);
        if ($tenant === null || !$this->definition->featureEnabled($tenant, $query)) {
            return null;
        }
        $context      = new SearchCandidateContext();
        $queryBuilder = $this->definition->candidateQuery($tenant, $query, $context);
        $this->assertNoReservedParameter($queryBuilder);
        $queryBuilder->andWhere($field . ' = :' . self::PARAMETER_PREFIX . 'id');
        $queryBuilder->setParameter(self::PARAMETER_PREFIX . 'id', $id);
        $visible = $this->visible($this->fetch($queryBuilder, 1), $tenant, $query, $group, $context);
        if ($visible === []) {
            return null;
        }
        $item = $this->items([$visible[0]], $query)[0];

        return $item->url === $reference->url && ($item->kind ?? $group) === $reference->kind ? $item : null;
    }

    /**
     * Refuses a query on which the definition (baseQuery(), applyScope() or applyFullText()) already bound a parameter
     * SearchKit reserves. Checked right before SearchKit binds its own parameters, so none can be overwritten.
     *
     * @throws LogicException When a parameter name starts with PARAMETER_PREFIX
     */
    private function assertNoReservedParameter(DbalQueryBuilder|OrmQueryBuilder $queryBuilder): void
    {
        $names = $queryBuilder instanceof DbalQueryBuilder
            ? array_keys($queryBuilder->getParameters())
            : array_map(static fn (Parameter $parameter): string => $parameter->getName(), $queryBuilder->getParameters()->toArray());
        foreach ($names as $name) {
            if (str_starts_with((string) $name, self::PARAMETER_PREFIX)) {
                throw new LogicException('An EntitySearchDefinition (baseQuery(), applyScope(), applyFullText()) must not bind parameters named "' . self::PARAMETER_PREFIX . '*": SearchKit binds them.');
            }
        }
    }

    /**
     * Whether the group may run: on in the settings, and not a sensitive group while recents keep titles.
     */
    private function runs(string $group): bool
    {
        if (!$this->groups->isEnabled($group)) {
            return false;
        }
        if (!$this->recentsSafe && $this->definition->sensitive()) {
            $this->notice?->report($group);

            return false;
        }

        return true;
    }

    /**
     * Full text when the definition handles it, otherwise every word must occur in one of the fields (escaped LIKE).
     */
    private function match(DbalQueryBuilder|OrmQueryBuilder $queryBuilder, string $text, SearchQuery $query): void
    {
        if ($this->definition->applyFullText($queryBuilder, SearchWords::fullTextTerms($text), $query)) {
            return;
        }
        $this->assertNoReservedParameter($queryBuilder);
        $fields = $this->definition->searchableFields();
        foreach (SearchWords::words($text) as $index => $word) {
            $parameter = self::PARAMETER_PREFIX . 'w' . $index;
            $queryBuilder->andWhere(LikePattern::anyField($fields, $parameter));
            $queryBuilder->setParameter($parameter, LikePattern::contains($word));
        }
    }

    /**
     * Runs the query (one round trip), at most `$limit` rows.
     *
     * @throws LogicException When the ORM query does not return a list of rows
     *
     * @return list<array<string, mixed>|object>
     */
    private function fetch(DbalQueryBuilder|OrmQueryBuilder $queryBuilder, int $limit): array
    {
        $queryBuilder->setFirstResult(0);
        $queryBuilder->setMaxResults($limit);
        if ($queryBuilder instanceof DbalQueryBuilder) {
            /** @var list<array<string, mixed>|object> $rows */
            $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

            return $rows;
        }
        $rows = $queryBuilder->getQuery()->getResult();
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new LogicException('The ORM query of an entity search must return a list of rows.');
        }

        /* @var list<array<string, mixed>|object> $rows */
        return $rows;
    }

    /**
     * The candidates the bundle's check keeps and no host listener denies, in order.
     *
     * @param list<array<string, mixed>|object> $rows
     *
     * @return list<array<string, mixed>|object>
     */
    private function visible(array $rows, int|string $tenant, SearchQuery $query, string $group, SearchCandidateContext $context): array
    {
        if ($rows === []) {
            return [];
        }
        $type = $this->definition->subjectType();
        $kept = [];
        foreach ($this->definition->filterCandidates($rows, $tenant, $query, $context) as $row) {
            $kept[$this->subject($type, $row)->key()] = true;
        }
        $candidates = [];
        foreach ($rows as $row) {
            $subject = $this->subject($type, $row);
            if (isset($kept[$subject->key()])) {
                $candidates[] = [$row, $subject];
            }
        }
        if ($candidates !== [] && $this->dispatcher instanceof EventDispatcherInterface) {
            $event = new SearchItemVisibilityEvent($group, array_map(static fn (array $candidate): SearchSubject => $candidate[1], $candidates), $query->userIdentifier, $tenant, $query->scope, $query->locale);
            $this->dispatcher->dispatch($event, SearchKitEvents::ITEM_VISIBILITY);
            $candidates = array_values(array_filter($candidates, static fn (array $candidate): bool => !$event->isDenied($candidate[1])));
        }

        return array_map(static fn (array $candidate): array|object => $candidate[0], $candidates);
    }

    /**
     * @param array<string, mixed>|object $row
     */
    private function subject(string $type, array|object $row): SearchSubject
    {
        return new SearchSubject($type, (string) $this->definition->subjectId($row));
    }

    /**
     * The rows that are shown, rendered: one prepareItems() call with all of them and a new SearchItemContext (local
     * to this call, dropped when it returns), then toPreparedItem() per row.
     *
     * @param non-empty-list<array<string, mixed>|object> $rows
     *
     * @return non-empty-list<SearchItem>
     */
    private function items(array $rows, SearchQuery $query): array
    {
        $context = new SearchItemContext();
        $this->definition->prepareItems($rows, $query, $context);

        return array_map(fn (array|object $row): SearchItem => $this->item($row, $query, $context), $rows);
    }

    /**
     * The definition's row, with the default icon when it sets none.
     *
     * @param array<string, mixed>|object $row
     */
    private function item(array|object $row, SearchQuery $query, SearchItemContext $context): SearchItem
    {
        $item = $this->definition->toPreparedItem($row, $query, $context);
        $icon = $this->definition->icon();
        if ($item->icon !== null || $icon === null) {
            return $item;
        }

        return new SearchItem($item->title, $item->url, $item->subtitle, $icon, $item->badge, $item->kind, $item->score, $item->date, $item->shortcut);
    }

    private function seeAllLabel(SearchQuery $query, int $total): string
    {
        return $this->translator instanceof TranslatorInterface
            ? $this->translator->trans('search.see_all', ['%count%' => $total], NowoSearchKitBundle::TRANSLATION_DOMAIN, $query->locale)
            : 'See all';
    }
}
