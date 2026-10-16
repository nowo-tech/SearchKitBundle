<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Scope;

use Nowo\SearchKitBundle\Contract\SearchScopeResolverInterface;
use Nowo\SearchKitBundle\Model\SearchScope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Default scope resolver: no scope, the search always covers everything the user may see.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NullScopeResolver implements SearchScopeResolverInterface
{
    /**
     * Resolves no scope.
     *
     * @param Request $request The main request
     * @param UserInterface $user The signed-in user
     *
     * @return SearchScope|null Always null
     */
    public function resolve(Request $request, UserInterface $user): ?SearchScope
    {
        return null;
    }
}
