<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search;

use Nowo\SearchKitBundle\Model\SearchFilter;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchQuery;

/**
 * What SearchEngine::execute() returns (1.8.0): the visible groups, the parsed filters (for the chips) and the query
 * the providers got.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchResult
{
    /**
     * @param list<SearchGroup> $groups Visible, non-empty groups (Actions first), each cut to the row limit
     * @param list<SearchFilter> $filters Typed filters recognised in the text
     * @param SearchQuery|null $query The query the providers got, or null when nothing ran (too short)
     * @param bool $actionsOnly The text started with the actions prefix (`>`)
     */
    public function __construct(
        public array $groups = [],
        public array $filters = [],
        public ?SearchQuery $query = null,
        public bool $actionsOnly = false,
    ) {
    }
}
