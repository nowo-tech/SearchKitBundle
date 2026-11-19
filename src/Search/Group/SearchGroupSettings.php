<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search\Group;

use Nowo\SearchKitBundle\Model\SearchGroup;

use function array_keys;
use function array_map;
use function usort;

/**
 * Per-group switches of `nowo_search_kit.groups` (1.9.0): `groups.<key>.enabled` and `groups.<key>.priority`, applied
 * by SearchKit to every provider whose group key is known — never re-implemented by a bundle.
 *
 * - enabled false: the provider does not run (EntitySearchProvider, PaginatedSearchProviderInterface), and any group
 *   with that key is dropped from the answer;
 * - priority: once any group has one, the groups of an answer are sorted by it (highest first); a group without one
 *   counts as 0, and equal priorities keep provider order. The Actions group stays first.
 *
 * Immutable (the configuration only): safe in FrankenPHP worker mode.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchGroupSettings
{
    /**
     * @param array<string, array{enabled?: bool, priority?: int|null}> $groups Settings by group key
     */
    public function __construct(
        private array $groups = [],
    ) {
    }

    /**
     * Whether a group may run and be shown (true unless configured off).
     *
     * @param string $key Group key
     */
    public function isEnabled(string $key): bool
    {
        return $this->groups[$key]['enabled'] ?? true;
    }

    /**
     * The configured priority of a group, or null.
     *
     * @param string $key Group key
     */
    public function priority(string $key): ?int
    {
        return $this->groups[$key]['priority'] ?? null;
    }

    /**
     * Whether any group has a configured priority.
     */
    public function hasPriorities(): bool
    {
        foreach (array_keys($this->groups) as $key) {
            if ($this->priority($key) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The groups sorted by priority (stable; unchanged without any configured priority).
     *
     * @param list<SearchGroup> $groups Groups in provider order
     * @param string|null $first Key kept on top whatever its priority (the Actions group), or null
     *
     * @return list<SearchGroup>
     */
    public function sort(array $groups, ?string $first = null): array
    {
        if (!$this->hasPriorities()) {
            return $groups;
        }
        $rows = array_map(fn (SearchGroup $group, int $index): array => [
            $group->key === $first ? 1 : 0,
            $this->priority($group->key) ?? 0,
            $index,
            $group,
        ], $groups, array_keys($groups));
        usort($rows, static fn (array $a, array $b): int => [$b[0], $b[1], $a[2]] <=> [$a[0], $a[1], $b[2]]);

        return array_map(static fn (array $row): SearchGroup => $row[3], $rows);
    }
}
