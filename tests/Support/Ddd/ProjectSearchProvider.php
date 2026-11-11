<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Ddd;

use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;

/** DDD host adapter: reads only the user identifier and the scope, asks the read model, maps to the bundle VOs. */
final readonly class ProjectSearchProvider implements SearchProviderInterface
{
    public function __construct(private ProjectDirectory $projects)
    {
    }

    public function search(SearchQuery $query): ?SearchGroup
    {
        $items = [];
        foreach ($this->projects->visibleTo($query->userIdentifier) as $id => $name) {
            if (($query->scope === null || $query->scope === $id) && $query->matches($name, $id)) {
                $items[] = new SearchItem($name, '/projects/' . $id . '/page', $id);
            }
        }

        return $items === [] ? null : new SearchGroup('projects', 'Projects', $items);
    }
}
