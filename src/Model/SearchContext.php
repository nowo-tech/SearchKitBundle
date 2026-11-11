<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Model;

use function in_array;

/**
 * The page a search box is rendered on, as plain values: what a SearchContextScopeResolverInterface gets instead of
 * the Symfony `Request` and security user (REQ-ARCH-001 rule 3).
 *
 * The bundle builds it from the main request and the signed-in user, per call, and never keeps it. There is no
 * anonymous context: for a guest the bundle resolves no scope and never asks the resolver, exactly as before.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchContext
{
    /**
     * @param string $userIdentifier The signed-in user's identifier (`UserInterface::getUserIdentifier()`)
     * @param string $path Path info of the main request (`Request::getPathInfo()`, no query string)
     * @param string|null $routeName Matched route name (`_route`), or null
     * @param array<string, scalar|null> $routeParameters Matched route parameters (`_route_params`, scalars only)
     * @param string|null $locale Request locale, or null
     * @param list<string> $roles The user's roles as the user object reports them (`getRoles()`: not expanded by the
     *                            role hierarchy; use your own access port for permission checks)
     */
    public function __construct(
        public string $userIdentifier,
        public string $path,
        public ?string $routeName = null,
        public array $routeParameters = [],
        public ?string $locale = null,
        public array $roles = [],
    ) {
    }

    /**
     * Whether the user object reports this role (exact match, no role hierarchy).
     *
     * @param string $role Role name, e.g. `ROLE_ADMIN`
     *
     * @return bool True when $roles contains it
     */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }
}
