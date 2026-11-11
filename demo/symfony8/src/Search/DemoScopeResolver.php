<?php

declare(strict_types=1);

namespace App\Search;

use Nowo\SearchKitBundle\Contract\SearchContextScopeResolverInterface;
use Nowo\SearchKitBundle\Model\SearchContext;
use Nowo\SearchKitBundle\Model\SearchScope;

/**
 * On `/{_locale}/projects/{id}` the search starts scoped to that project — only when the user may open it.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class DemoScopeResolver implements SearchContextScopeResolverInterface
{
    public function resolveFor(SearchContext $context): ?SearchScope
    {
        if ($context->routeName !== 'demo_project') {
            return null;
        }
        $id       = (string) ($context->routeParameters['id'] ?? '');
        $projects = DemoData::projectsFor($context->userIdentifier);

        return isset($projects[$id]) ? new SearchScope($id, $projects[$id][0]) : null;
    }
}
