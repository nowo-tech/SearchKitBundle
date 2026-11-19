<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Event;

/**
 * Names of the events SearchKit dispatches (1.9.0).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class SearchKitEvents
{
    /**
     * SearchItemVisibilityEvent: once per search page of an EntitySearchProvider, with every candidate the bundle's
     * own access check kept. A host listener may deny subjects (deny wins); a denied row is dropped before it is
     * counted, so it looks exactly like a row that does not exist.
     */
    public const string ITEM_VISIBILITY = 'nowo_search_kit.item_visibility';
}
