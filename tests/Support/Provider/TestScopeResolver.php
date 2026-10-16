<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Provider;

use Nowo\SearchKitBundle\Contract\SearchScopeResolverInterface;
use Nowo\SearchKitBundle\Model\SearchScope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/** Test resolver: `/projects/{id}/…` scopes to that project when the user may see it. */
final class TestScopeResolver implements SearchScopeResolverInterface
{
    public function resolve(Request $request, UserInterface $user): ?SearchScope
    {
        if (preg_match('#^/projects/([a-z]+)/#', $request->getPathInfo(), $m) !== 1 || !isset(ProjectsProvider::PROJECTS[$m[1]])) {
            return null;
        }
        if ($user->getUserIdentifier() !== 'alice' && $m[1] !== 'beta') {
            return null;
        }

        return new SearchScope($m[1], ProjectsProvider::PROJECTS[$m[1]]);
    }
}
