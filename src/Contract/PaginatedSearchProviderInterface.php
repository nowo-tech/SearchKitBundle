<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Contract;

use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchQuery;

/**
 * A provider whose group can be paged on the results page (1.8.0, `results_page`): the tab of its group shows
 * `per_page` rows and previous / next links.
 *
 * Contract: the same security rules as search() — only rows the viewer may open, filtered **in the query** (before the
 * LIMIT / OFFSET), so a page never contains a gap that reveals a hidden row. No total is needed: the page shows a
 * "next" link while a page comes back full.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface PaginatedSearchProviderInterface extends SearchProviderInterface
{
    /**
     * Key of the group this provider returns (the results page finds the provider of a tab with it).
     *
     * @return string The SearchGroup key
     */
    public function groupKey(): string;

    /**
     * One page of this provider's rows.
     *
     * @param SearchQuery $query The query ($limit is $perPage)
     * @param int $page Page number, from 1 (at most `results_page.max_page`)
     * @param int $perPage Rows per page
     *
     * @return SearchGroup|null The group with at most $perPage rows, or null when the page is empty
     */
    public function searchPage(SearchQuery $query, int $page, int $perPage): ?SearchGroup;
}
