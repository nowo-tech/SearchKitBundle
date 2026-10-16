<?php

declare(strict_types=1);

namespace App\Search;

use Nowo\SearchKitBundle\Contract\SearchScopeResolverInterface;
use Nowo\SearchKitBundle\Model\SearchScope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * On `/{_locale}/projects/{id}` the search starts scoped to that project — only when the user may open it.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class DemoScopeResolver implements SearchScopeResolverInterface
{
    public function resolve(Request $request, UserInterface $user): ?SearchScope
    {
        if ($request->attributes->get('_route') !== 'demo_project') {
            return null;
        }
        $id       = (string) $request->attributes->get('id');
        $projects = DemoData::projectsFor($user);

        return isset($projects[$id]) ? new SearchScope($id, $projects[$id][0]) : null;
    }
}
