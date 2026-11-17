<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search\Action;

use Countable;
use Nowo\SearchKitBundle\Contract\SearchActionProviderInterface;
use Nowo\SearchKitBundle\Model\SearchAccessRequest;
use Nowo\SearchKitBundle\Model\SearchAction;
use Nowo\SearchKitBundle\Model\SearchContext;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Search\Access\SearchAccess;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function array_slice;
use function count;

/**
 * The palette's actions (1.8.0): SearchActionProviderInterface services, each action allowed by the access checkers
 * (deny by default), with the static `quick_actions` as the fallback when no provider is registered.
 *
 * - emptyQueryRows(): what the box lists while the query is empty (providers, or the quick actions);
 * - search(): the Actions group of a query (on top of the results; the only group of a `>` query).
 *
 * The quick actions keep their 1.x rules (role check, no access checker). Holds no state.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ActionCollector
{
    public const string KEY = 'actions';

    /**
     * @param iterable<SearchActionProviderInterface> $providers Tagged action providers, highest priority first
     * @param SearchAccess $access Access checkers
     * @param list<array{label: string, translation_domain: string|null, route: string|null, route_parameters: array<string, scalar>, url: string|null, kind: string, role: string|null}> $quickActions The configured quick actions
     * @param UrlGeneratorInterface $urls Builds the quick action URLs
     * @param TranslatorInterface $translator Translates the quick action labels and the group heading
     * @param Security|null $security Role check of the quick actions (null: role-gated quick actions are skipped)
     * @param int $maxCandidates Most actions read per call
     * @param LoggerInterface|null $logger Failing providers are reported here
     */
    public function __construct(
        private iterable $providers,
        private SearchAccess $access,
        private array $quickActions,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private ?Security $security = null,
        private int $maxCandidates = 200,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Whether any action provider is registered (the quick actions are then only a fallback).
     *
     * @return bool True with at least one provider
     */
    public function hasProviders(): bool
    {
        if ($this->providers instanceof Countable) {
            return count($this->providers) > 0;
        }
        foreach ($this->providers as $ignored) {
            return true;
        }

        return false;
    }

    /**
     * The actions of the providers that the viewer may open, for a context.
     *
     * @param SearchContext $context The viewer and the page
     * @param string|null $scope Scope id, or null
     *
     * @return list<SearchAction> Allowed actions, in provider order, at most `max_candidates` read
     */
    public function forContext(SearchContext $context, ?string $scope): array
    {
        $actions = [];
        $read    = 0;
        foreach ($this->providers as $provider) {
            try {
                foreach ($provider->actions($context, $scope) as $action) {
                    if ($read++ >= $this->maxCandidates) {
                        break 2;
                    }
                    if ($this->access->allowsUrl($action->url, $context->userIdentifier, SearchAccessRequest::KIND_ACTION, $scope)) {
                        $actions[] = $action;
                    }
                }
            } catch (Throwable $exception) {
                $this->logger?->error('Search action provider {provider} failed: {exception}.', [
                    'provider'  => $provider::class,
                    'exception' => $exception::class,
                ]);
            }
        }

        return $actions;
    }

    /**
     * Rows listed while the query is empty: the providers' actions, or the quick actions when there is no provider.
     *
     * @param SearchContext $context The viewer and the page
     * @param string|null $scope Scope id, or null
     * @param bool $withRoles Whether the role of a quick action can be checked (false: role-gated ones are skipped)
     *
     * @return list<array{title: string, subtitle: string, url: string, kind: string, shortcut?: string}> The rows
     */
    public function emptyQueryRows(SearchContext $context, ?string $scope, bool $withRoles = true): array
    {
        if (!$this->hasProviders()) {
            return $this->quickActionRows($withRoles);
        }

        return array_map(static function (SearchAction $action): array {
            $row = ['title' => $action->label, 'subtitle' => $action->context ?? '', 'url' => $action->url, 'kind' => 'action'];
            if ($action->shortcut !== null && $action->shortcut !== '') {
                $row['shortcut'] = $action->shortcut;
            }

            return $row;
        }, $this->forContext($context, $scope));
    }

    /**
     * The configured quick actions the viewer may see (1.x rules).
     *
     * @param bool $withRoles Whether role-gated entries can be checked (false: they are skipped)
     *
     * @return list<array{title: string, subtitle: string, url: string, kind: string}> The rows
     */
    public function quickActionRows(bool $withRoles = true): array
    {
        $rows = [];
        foreach ($this->quickActions as $action) {
            if ($action['role'] !== null && (!$withRoles || !$this->security instanceof Security || !$this->security->isGranted($action['role']))) {
                continue;
            }
            $rows[] = [
                'title'    => $this->translator->trans($action['label'], [], $action['translation_domain']),
                'subtitle' => '',
                'url'      => $action['route'] !== null ? $this->urls->generate($action['route'], $action['route_parameters']) : (string) $action['url'],
                'kind'     => $action['kind'],
            ];
        }

        return $rows;
    }

    /**
     * The Actions group of a query: actions whose label or keywords match (all of them for an empty `>` query).
     *
     * @param SearchQuery $query The query (text without the `>` prefix)
     * @param SearchContext $context The viewer and the page
     * @param bool $withRoles See quickActionRows() (used when there is no provider)
     * @param int $limit Most rows
     *
     * @return SearchGroup|null The group (exact total), or null when nothing matches
     */
    public function search(SearchQuery $query, SearchContext $context, bool $withRoles, int $limit): ?SearchGroup
    {
        $items = [];
        if ($this->hasProviders()) {
            foreach ($this->forContext($context, $query->scope) as $action) {
                $score = $query->text === '' ? null : $query->score($action->label, ...$action->keywords);
                if ($query->text === '' || $score !== null) {
                    $items[] = $action->toItem($score);
                }
            }
        } else {
            foreach ($this->quickActionRows($withRoles) as $row) {
                $score = $query->text === '' ? null : $query->score($row['title']);
                if (($query->text === '' || $score !== null) && SearchItem::isSafeUrl($row['url'])) {
                    $items[] = new SearchItem($row['title'], $row['url'], '', null, null, $row['kind'], $score);
                }
            }
        }
        if ($items === []) {
            return null;
        }

        return new SearchGroup(self::KEY, $this->translator->trans('search.group.actions', [], 'NowoSearchKitBundle'), array_slice($items, 0, max(1, $limit)), null, null, null, true);
    }
}
