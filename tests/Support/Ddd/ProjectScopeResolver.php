<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Ddd;

use Nowo\SearchKitBundle\Contract\SearchContextScopeResolverInterface;
use Nowo\SearchKitBundle\Model\SearchContext;
use Nowo\SearchKitBundle\Model\SearchScope;

use function is_string;

/** DDD host adapter on the 1.7 port only: the project of the route, when the user is a member. */
final readonly class ProjectScopeResolver implements SearchContextScopeResolverInterface
{
    public function __construct(private ProjectDirectory $projects)
    {
    }

    public function resolveFor(SearchContext $context): ?SearchScope
    {
        $id = $context->routeParameters['id'] ?? null;
        if ($context->routeName !== DddHostKernel::PROJECT_ROUTE || !is_string($id)) {
            return null;
        }
        $visible = $this->projects->visibleTo($context->userIdentifier);

        return isset($visible[$id]) ? new SearchScope($id, $visible[$id]) : null;
    }
}
