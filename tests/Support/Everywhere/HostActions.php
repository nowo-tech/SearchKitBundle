<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Everywhere;

use Nowo\SearchKitBundle\Contract\SearchActionProviderInterface;
use Nowo\SearchKitBundle\Model\SearchAction;
use Nowo\SearchKitBundle\Model\SearchContext;

/**
 * Palette actions of the tests: a scoped one when the page or the scope is a project.
 */
final class HostActions implements SearchActionProviderInterface
{
    public function actions(SearchContext $context, ?string $scope): iterable
    {
        yield new SearchAction('New issue', '/issues/new', ['create', 'ticket', 'nueva incidencia'], 'tabler:bug', 'n i');
        yield new SearchAction('Admin console', '/admin', ['secret-admin-keyword']);
        yield new SearchAction('Read the docs', 'https://example.com/docs', ['help']);
        yield new SearchAction('Unrouted', '/nowhere', ['unrouted']);
        $project = $scope ?? ($context->routeName === 'test_project_page' ? (string) ($context->routeParameters['id'] ?? '') : null);
        if ($project !== null && $project !== '') {
            yield new SearchAction('Project members', '/projects/' . rawurlencode($project) . '/tabs/members', ['team'], null, null, $project);
        }
    }
}
