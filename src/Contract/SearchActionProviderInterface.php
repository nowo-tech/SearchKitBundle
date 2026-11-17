<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Contract;

use Nowo\SearchKitBundle\Model\SearchAction;
use Nowo\SearchKitBundle\Model\SearchContext;

/**
 * Commands of the palette (1.8.0): the Actions group on top of the results, the only group of a `>` query, and the
 * list shown while the query is empty (instead of the static `quick_actions`, kept as the fallback when no provider
 * exists).
 *
 * Every service implementing it is tagged `nowo_search_kit.action_provider` (autoconfiguration); providers are read by
 * tag priority, highest first.
 *
 * Contract: actions are **GET links** (a URL), never a form or a POST — the bundle cannot run anything. Each action is
 * listed only when the SearchAccessCheckerInterface allows its URL for the viewer (deny by default); still return
 * only actions that make sense for the context (the current page, the scope).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface SearchActionProviderInterface
{
    /** Service tag of the implementations (autoconfigured). */
    public const string ACTION_PROVIDER_TAG = 'nowo_search_kit.action_provider';

    /**
     * The actions for this context.
     *
     * @param SearchContext $context The viewer and the page the search box is on (path / route of the page that asked)
     * @param string|null $scope Scope id of the search (client input: an unknown or forbidden id yields no scoped action)
     *
     * @return iterable<SearchAction> The actions (the bundle reads at most `actions.max_candidates` of them)
     */
    public function actions(SearchContext $context, ?string $scope): iterable;
}
