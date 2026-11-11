<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Provider;

use LogicException;
use Nowo\SearchKitBundle\Contract\SearchContextScopeResolverInterface;
use Nowo\SearchKitBundle\Contract\SearchScopeResolverInterface;
use Nowo\SearchKitBundle\Model\SearchContext;
use Nowo\SearchKitBundle\Model\SearchScope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/** Implements both ports and is aliased to the old one: the bundle must ask the new one only. */
final class DualScopeResolver implements SearchContextScopeResolverInterface, SearchScopeResolverInterface
{
    public function resolveFor(SearchContext $context): ?SearchScope
    {
        return ScopePolicy::scope($context->path, $context->userIdentifier);
    }

    public function resolve(Request $request, UserInterface $user): ?SearchScope
    {
        throw new LogicException('The deprecated port must not be asked when the new one is implemented.');
    }
}
