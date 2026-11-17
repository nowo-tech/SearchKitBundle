<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Everywhere;

use Nowo\SearchKitBundle\Contract\SearchPageSourceInterface;
use Nowo\SearchKitBundle\Model\SearchPage;
use Nowo\SearchKitBundle\Model\SearchQuery;

/**
 * Page source of the tests: the tabs of the project in scope (the bundle still asks the access checker for each one).
 */
final class ProjectTabs implements SearchPageSourceInterface
{
    public function pages(SearchQuery $query): iterable
    {
        if ($query->scope === null || !isset(Directory::PROJECTS[$query->scope])) {
            return [];
        }

        return [
            new SearchPage('tab.issues.' . $query->scope, 'Issues', 'test_project_tab', ['tab' => 'issues'], null, ['Project'], null, 'bugs, incidencias', SearchPage::SCOPE_REQUIRED, null, null, 'id'),
            new SearchPage('tab.files.' . $query->scope, 'Files', 'test_project_tab', ['tab' => 'files'], null, ['Project'], null, 'documents', SearchPage::SCOPE_REQUIRED, null, null, 'id'),
        ];
    }
}
