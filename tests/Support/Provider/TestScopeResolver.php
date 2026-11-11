<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Provider;

use Nowo\SearchKitBundle\Contract\SearchScopeResolverInterface;
use Nowo\SearchKitBundle\Model\SearchScope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/** Test resolver on the deprecated port: `/projects/{id}/…` scopes to that project when the user may see it. */
final class TestScopeResolver implements SearchScopeResolverInterface
{
    public function resolve(Request $request, UserInterface $user): ?SearchScope
    {
        return ScopePolicy::scope($request->getPathInfo(), $user->getUserIdentifier());
    }
}
