<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Contract;

use Nowo\SearchKitBundle\Model\SearchPage;
use Nowo\SearchKitBundle\Model\SearchQuery;

/**
 * Registry entries computed at runtime (1.8.0): e.g. the tabs of the project in scope, or DashboardMenuBundle's project
 * menus. They join the configured `nowo_search_kit.pages` in the Pages group.
 *
 * Every service implementing it is tagged `nowo_search_kit.page_source` (autoconfiguration).
 *
 * Security contract: return entries for the scope and the viewer of the query only (an unknown or forbidden scope
 * yields nothing). The bundle still asks the SearchAccessCheckerInterface for every entry (deny by default) before it
 * matches, counts or renders it, so a source never has to hide entries by itself for the search to be safe — but it
 * must not reveal anything through the entries it builds (labels of records the viewer may not open).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface SearchPageSourceInterface
{
    /** Service tag of the implementations (autoconfigured). */
    public const string PAGE_SOURCE_TAG = 'nowo_search_kit.page_source';

    /**
     * The entries for this query (its scope, its viewer, its locale).
     *
     * @param SearchQuery $query The query (read $scope, $userIdentifier and $locale; the text is matched by the bundle)
     *
     * @return iterable<SearchPage> The entries (the bundle reads at most `matching.max_candidates` of them)
     */
    public function pages(SearchQuery $query): iterable;
}
