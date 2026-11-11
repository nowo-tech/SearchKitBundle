<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Provider;

use Nowo\SearchKitBundle\Contract\SearchContextScopeResolverInterface;
use Nowo\SearchKitBundle\Model\SearchContext;
use Nowo\SearchKitBundle\Model\SearchScope;

/**
 * The policy of TestScopeResolver written once more on the 1.7 port: `/projects/{id}/…` scopes to that project when
 * the user may see it (alice: every project, everyone else: beta only).
 */
final class ContextScopeResolver implements SearchContextScopeResolverInterface
{
    public function resolveFor(SearchContext $context): ?SearchScope
    {
        return ScopePolicy::scope($context->path, $context->userIdentifier);
    }
}
