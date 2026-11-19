<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\EntitySearch;

use Nowo\SearchKitBundle\Contract\SearchAccessCheckerInterface;
use Nowo\SearchKitBundle\Model\SearchAccessRequest;

/**
 * Host access checker of the kernel notes route (recents revalidation asks it first).
 */
final class NoteRouteChecker implements SearchAccessCheckerInterface
{
    public function decide(SearchAccessRequest $request): ?bool
    {
        return $request->route === 'test_note' ? $request->userIdentifier === 'alice' : null;
    }
}
