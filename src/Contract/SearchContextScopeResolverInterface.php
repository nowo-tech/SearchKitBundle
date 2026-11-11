<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Contract;

use Nowo\SearchKitBundle\Model\SearchContext;
use Nowo\SearchKitBundle\Model\SearchScope;

/**
 * The scope the search starts in on the current page (e.g. the project of a `/projects/{id}/…` URL), shown as a
 * removable chip; its id is sent as `scope` and reaches the providers as SearchQuery::$scope.
 *
 * Framework-free twin of the deprecated SearchScopeResolverInterface (1.7.0): it gets a SearchContext VO (path, route,
 * locale, user identifier, roles) instead of the Symfony `Request` and security user. Alias this interface to your
 * implementation; the bundle default (NullScopeResolver) resolves nothing.
 *
 * Security contract: the scope is a permission decision. Return a scope only when the user may open it: the chip must
 * never name something the user cannot see. The bundle never calls it for a guest (no signed-in user) or outside a
 * request; it then resolves no scope.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface SearchContextScopeResolverInterface
{
    /**
     * Resolves the scope of the page being rendered.
     *
     * @param SearchContext $context The page and the signed-in user, as plain values
     *
     * @return SearchScope|null The scope (id + label), or null to search everywhere
     */
    public function resolveFor(SearchContext $context): ?SearchScope;
}
