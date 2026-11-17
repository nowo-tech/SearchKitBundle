<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search\Ranking;

use DateTimeImmutable;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;

use function array_slice;
use function count;

/**
 * Relevance + recency ranking (1.8.0, `ranking`).
 *
 * A row's rank is its SearchItem::$score (0 when null) plus `recency_weight` × 0.5^(age in days / `half_life_days`)
 * when it has a SearchItem::$date. Rows are sorted inside their group only when at least one row has a score or a
 * date (otherwise the provider order is kept, as before 1.8.0); ties keep the provider order. It runs on rows that
 * already passed the permission filter: ranking never sees a hidden row. Stateless.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchRanker
{
    /**
     * @param float $recencyWeight Boost of a row changed right now (0: recency ignored)
     * @param float $halfLifeDays Age at which the boost halves
     */
    public function __construct(
        private float $recencyWeight = 0.25,
        private float $halfLifeDays = 30.0,
    ) {
    }

    /**
     * Rank of one row.
     *
     * @param SearchItem $item The row
     * @param DateTimeImmutable $now Reference time
     *
     * @return float The score plus the recency boost
     */
    public function rank(SearchItem $item, DateTimeImmutable $now): float
    {
        $rank = $item->score ?? 0.0;
        if ($item->date instanceof DateTimeImmutable && $this->recencyWeight > 0.0) {
            $days = max(0.0, ($now->getTimestamp() - $item->date->getTimestamp()) / 86400);
            $rank += $this->recencyWeight * 0.5 ** ($days / max(0.001, $this->halfLifeDays));
        }

        return $rank;
    }

    /**
     * The group with its rows sorted by rank (stable), when any row is ranked.
     *
     * @param SearchGroup $group The group
     * @param DateTimeImmutable $now Reference time
     *
     * @return SearchGroup The same group, or a sorted copy
     */
    public function sort(SearchGroup $group, DateTimeImmutable $now): SearchGroup
    {
        $ranked = false;
        foreach ($group->items as $item) {
            $ranked = $ranked || $item->score !== null || $item->date !== null;
        }
        if (!$ranked) {
            return $group;
        }

        return $group->withItems($this->sortItems($group->items, $now), $group->total, $group->exactTotal);
    }

    /**
     * The best rows across groups, for the Top results section of the results page: only rows with a score.
     *
     * @param list<SearchGroup> $groups Groups (already filtered for the viewer)
     * @param int $limit Most rows
     * @param DateTimeImmutable $now Reference time
     *
     * @return list<array{group: string, item: SearchItem}> The rows with the key of their group, best first
     */
    public function top(array $groups, int $limit, DateTimeImmutable $now): array
    {
        $rows = [];
        foreach ($groups as $group) {
            foreach ($group->items as $item) {
                if ($item->score !== null) {
                    $rows[] = ['group' => $group->key, 'item' => $item, 'rank' => $this->rank($item, $now), 'order' => count($rows)];
                }
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$b['rank'], $a['order']] <=> [$a['rank'], $b['order']]);

        return array_map(static fn (array $row): array => ['group' => $row['group'], 'item' => $row['item']], array_slice($rows, 0, max(0, $limit)));
    }

    /**
     * @param list<SearchItem> $items
     *
     * @return list<SearchItem>
     */
    private function sortItems(array $items, DateTimeImmutable $now): array
    {
        $rows = [];
        foreach ($items as $order => $item) {
            $rows[] = [$this->rank($item, $now), $order, $item];
        }
        usort($rows, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return array_map(static fn (array $row): SearchItem => $row[2], $rows);
    }
}
