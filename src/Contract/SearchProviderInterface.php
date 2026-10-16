<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Contract;

use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchQuery;

/**
 * One group of the global search (Pages, Projects, Issues, …), supplied by the application.
 *
 * Every service implementing this interface is tagged `nowo_search_kit.provider` (autoconfiguration); groups are
 * listed by tag priority, highest first (`#[AsTaggedItem(priority: 50)]` or the tag's `priority` attribute).
 *
 * Security contract: a provider returns **only rows the current user may open** and never hints at anything else
 * (no counts, no titles of hidden records). An unknown or inaccessible scope id yields no rows — never an exception,
 * never a 403 — so the endpoint cannot be used as an existence oracle.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface SearchProviderInterface
{
    public const string TAG = 'nowo_search_kit.provider';

    /**
     * Searches this provider's records.
     *
     * @param SearchQuery $query Trimmed text (at least `min_length` characters), optional scope id, the user and the row limit
     *
     * @return SearchGroup|null The group, or null when the provider has nothing for this query (an empty group is skipped too)
     */
    public function search(SearchQuery $query): ?SearchGroup;
}
