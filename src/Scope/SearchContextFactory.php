<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Scope;

use Nowo\SearchKitBundle\Model\SearchContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_array;
use function is_scalar;
use function is_string;

/**
 * Builds the SearchContext VO from the main request and the signed-in user, at the HTTP edge. Holds no state.
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class SearchContextFactory
{
    /**
     * @param Request $request The main request
     * @param UserInterface $user The signed-in user
     *
     * @return SearchContext The page and the user as plain values
     */
    public static function create(Request $request, UserInterface $user): SearchContext
    {
        $route      = $request->attributes->get('_route');
        $parameters = $request->attributes->get('_route_params');
        $scalars    = [];
        foreach (is_array($parameters) ? $parameters : [] as $name => $value) {
            if (is_string($name) && ($value === null || is_scalar($value))) {
                $scalars[$name] = $value;
            }
        }

        return new SearchContext(
            $user->getUserIdentifier(),
            $request->getPathInfo(),
            is_string($route) ? $route : null,
            $scalars,
            $request->getLocale(),
            array_values($user->getRoles()),
        );
    }
}
