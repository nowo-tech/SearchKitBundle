<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Contract;

use Nowo\SearchKitBundle\Model\SearchFilterValue;

/**
 * Autocomplete of typed filter values (1.8.0): after `assignee:` or `project:` the field suggests values.
 *
 * Every service implementing it is tagged `nowo_search_kit.filter_values` (autoconfiguration).
 *
 * Security contract: return **only values the viewer may see** (the members of projects they belong to, the projects
 * they may open …). An unknown key, an unknown prefix and a forbidden one answer the same empty list.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface SearchFilterValuesProviderInterface
{
    /** Service tag of the implementations (autoconfigured). */
    public const string FILTER_VALUES_TAG = 'nowo_search_kit.filter_values';

    /**
     * Whether this service suggests values for a key.
     *
     * @param string $key A key of `nowo_search_kit.filters.keys`
     *
     * @return bool True to be asked for values()
     */
    public function supportsKey(string $key): bool;

    /**
     * Visible values of a key that start with (or contain) the prefix.
     *
     * @param string $key Filter key
     * @param string $prefix What the viewer typed after `key:` (may be empty; at most 100 characters)
     * @param string $userIdentifier The viewer
     * @param string|null $scope Scope id of the search, or null (client input)
     * @param int $limit Most values returned: honour it in your query (the bundle cuts longer lists, but it has to read
     *                   every value you yield first)
     *
     * @return list<SearchFilterValue> The suggestions
     */
    public function values(string $key, string $prefix, string $userIdentifier, ?string $scope, int $limit): array;
}
