<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Provider;

use LogicException;
use Nowo\SearchKitBundle\Contract\SearchScopeResolverInterface;
use Nowo\SearchKitBundle\Model\SearchScope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/** Deprecated-port resolver that must never be asked: the host also aliased the 1.7 port. */
final class UnusedLegacyScopeResolver implements SearchScopeResolverInterface
{
    public function resolve(Request $request, UserInterface $user): ?SearchScope
    {
        throw new LogicException('The 1.7 port alias wins over the deprecated one.');
    }
}
