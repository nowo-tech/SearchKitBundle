<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Everywhere;

use Nowo\SearchKitBundle\Contract\SearchAccessCheckerInterface;
use Nowo\SearchKitBundle\Model\SearchAccessRequest;

/**
 * A second checker: vetoes the "billing" settings section for everyone but admin, abstains on the rest.
 */
final class VetoChecker implements SearchAccessCheckerInterface
{
    public function decide(SearchAccessRequest $request): ?bool
    {
        return $request->route === 'test_settings' && ($request->routeParameters['section'] ?? null) === 'billing' && $request->userIdentifier !== 'admin' ? false : null;
    }
}
