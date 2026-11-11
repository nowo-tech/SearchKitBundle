<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Contract;

use Nowo\SearchKitBundle\Model\SearchGroup;

/**
 * Runs the search over every tagged SearchProviderInterface, for hosts that reuse the fan-out outside the bundle's
 * endpoint (CLI, API, another UI). Aliased to the bundle's SearchEngine.
 *
 * It takes the user identifier, not a Symfony user (REQ-ARCH-001 rule 3): the providers get it as
 * SearchQuery::$userIdentifier and SearchQuery::$user is null, so a provider that still reads the deprecated `$user`
 * fails, is logged and skipped (it never returns rows for someone else).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface SearchEngineInterface
{
    /**
     * Searches every provider on behalf of one user.
     *
     * @param string $userIdentifier The user the providers check permissions for
     * @param string $text Raw query (trimmed by the engine)
     * @param string|null $scope Scope id, or null
     *
     * @return list<SearchGroup> Non-empty groups, in provider order, each with at most `per_group` rows
     */
    public function searchFor(string $userIdentifier, string $text, ?string $scope = null): array;
}
