<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search\Query;

use function array_slice;
use function count;
use function max;
use function min;

/**
 * The bounded window of one search (1.9.0): how many candidate rows are read, which visible rows make the requested
 * page, and the visible total.
 *
 * The provider reads at most `candidates + 1` rows (the extra one only tells whether more exist), checks access on
 * the candidates, then cuts the page out of the **visible** rows. The total is the number of visible candidates; it
 * is exact only when the candidates were not cut (otherwise SearchKit caps it to the rows shown). A row the viewer may
 * not see is never counted. Immutable.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchWindow
{
    /** Most rows of one page. */
    public const int MAX_PER_PAGE = 100;

    /** Most candidates read and checked per search. */
    public const int MAX_CANDIDATES = 1000;

    /**
     * @param int $page Page number (from 1)
     * @param int $perPage Rows per page (1 to MAX_PER_PAGE)
     * @param int $candidates Candidates read and checked (1 to MAX_CANDIDATES)
     */
    private function __construct(
        public int $page,
        public int $perPage,
        public int $candidates,
    ) {
    }

    /**
     * The window of a page, or null when the page starts past the candidates (it could only ever be empty).
     *
     * @param int $page Page number (from 1)
     * @param int $perPage Rows per page (clamped to 1..MAX_PER_PAGE)
     * @param int $candidates Candidates read and checked (clamped to 1..MAX_CANDIDATES)
     *
     * @return self|null The window, or null for a page below 1 or past the candidates
     */
    public static function of(int $page, int $perPage, int $candidates): ?self
    {
        $perPage    = min(self::MAX_PER_PAGE, max(1, $perPage));
        $candidates = min(self::MAX_CANDIDATES, max(1, $candidates));
        if ($page < 1 || ($page - 1) * $perPage >= $candidates) {
            return null;
        }

        return new self($page, $perPage, $candidates);
    }

    /**
     * Rows to read: the candidates plus one, which only tells whether more exist.
     */
    public function fetchLimit(): int
    {
        return $this->candidates + 1;
    }

    /**
     * Whether a fetch returned more rows than the candidates (the total is then a lower bound).
     *
     * @param int $fetched Rows the query returned
     */
    public function isCut(int $fetched): bool
    {
        return $fetched > $this->candidates;
    }

    /**
     * The candidates of a fetch (the extra row dropped).
     *
     * @template T
     *
     * @param list<T> $rows Fetched rows
     *
     * @return list<T>
     */
    public function candidatesOf(array $rows): array
    {
        return array_slice($rows, 0, $this->candidates);
    }

    /**
     * The rows of the page, out of the visible candidates.
     *
     * @template T
     *
     * @param list<T> $visible Visible candidates, in order
     *
     * @return list<T>
     */
    public function slice(array $visible): array
    {
        return array_slice($visible, ($this->page - 1) * $this->perPage, $this->perPage);
    }

    /**
     * The visible total.
     *
     * @param list<mixed> $visible Visible candidates
     */
    public function total(array $visible): int
    {
        return count($visible);
    }
}
