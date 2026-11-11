<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Provider;

use Nowo\SearchKitBundle\Model\SearchScope;

/** The one scope policy of the tests, shared by the resolvers on both ports. */
final class ScopePolicy
{
    public static function scope(string $path, string $userIdentifier): ?SearchScope
    {
        if (preg_match('#^/projects/([a-z]+)/#', $path, $m) !== 1 || !isset(ProjectsProvider::PROJECTS[$m[1]])) {
            return null;
        }
        if ($userIdentifier !== 'alice' && $m[1] !== 'beta') {
            return null;
        }

        return new SearchScope($m[1], ProjectsProvider::PROJECTS[$m[1]]);
    }
}
