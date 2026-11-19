<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\EntitySearch;

/**
 * The request-scoped state the test definitions read on every call (what a host's tenant resolver and security
 * token give a real definition).
 */
final class Viewer
{
    public function __construct(
        public int|string|null $tenant = null,
        public string $userIdentifier = '',
        public bool $feature = true,
    ) {
    }
}
