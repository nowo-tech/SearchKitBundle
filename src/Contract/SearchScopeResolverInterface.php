<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Contract;

use Nowo\SearchKitBundle\Model\SearchScope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The scope the search starts in on the current page (e.g. the project of a `/projects/{id}/…` URL), shown as a
 * removable chip; its id is sent as `scope` and reaches the providers as SearchQuery::$scope.
 *
 * Alias this interface to your implementation; the bundle default resolves nothing. Return a scope only when the
 * user may open it: the chip must never name something the user cannot see.
 *
 * @deprecated since 1.7.0, removed in 2.0: implement SearchContextScopeResolverInterface (a SearchContext VO instead of
 *             the Symfony `Request` and user, REQ-ARCH-001 rule 3). A resolver that implements only this interface
 *             keeps working in 1.x and triggers a deprecation when it is called.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface SearchScopeResolverInterface
{
    /**
     * Resolves the scope of the page being rendered.
     *
     * @param Request $request The main request
     * @param UserInterface $user The signed-in user
     *
     * @return SearchScope|null The scope (id + label), or null to search everywhere
     */
    public function resolve(Request $request, UserInterface $user): ?SearchScope;
}
