<?php

declare(strict_types=1);

namespace App\Search;

use Nowo\SearchKitBundle\Contract\SearchPageSourceInterface;
use Nowo\SearchKitBundle\Model\SearchPage;
use Nowo\SearchKitBundle\Model\SearchQuery;

/**
 * Registry entries computed at runtime: the tabs of the project in scope (the bundle checks each one).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class DemoProjectTabs implements SearchPageSourceInterface
{
    public function pages(SearchQuery $query): iterable
    {
        if ($query->scope === null) {
            return [];
        }
        foreach (['issues', 'files', 'members'] as $tab) {
            yield new SearchPage('project.tab.' . $tab, 'demo.tab.' . $tab, 'demo_project_tab', ['_locale' => $query->locale ?? 'en', 'tab' => $tab], 'messages', ['demo.tab.trail'], 'tabler:folder', 'demo.tab.' . $tab . '_keywords', SearchPage::SCOPE_REQUIRED, null, null, 'id');
        }
    }
}
