<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Provider;

use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Model\SearchBadge;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

use function count;
use function in_array;

/**
 * Test provider: projects per user (alice sees acme + beta, everyone else only beta). A scope narrows to that
 * project when the user may see it, and yields nothing otherwise (no 403, no hint).
 */
#[AsTaggedItem(priority: 50)]
final class ProjectsProvider implements SearchProviderInterface
{
    /** @var array<string, string> */
    public const array PROJECTS = ['acme' => 'Acme Shop', 'beta' => 'Beta Shop', 'gamma' => 'Gamma Shop'];

    public function search(SearchQuery $query): ?SearchGroup
    {
        $visible = $query->user->getUserIdentifier() === 'alice' ? ['acme', 'beta', 'gamma'] : ['beta'];
        if ($query->scope !== null) {
            $visible = in_array($query->scope, $visible, true) ? [$query->scope] : [];
        }
        $items = [];
        foreach ($visible as $id) {
            if ($query->matches(self::PROJECTS[$id], $id)) {
                $items[] = new SearchItem(
                    self::PROJECTS[$id],
                    '/projects/' . $id,
                    $id,
                    $id === 'acme' ? 'tabler:bug' : 'tabler:missing',
                    $id === 'acme' ? new SearchBadge('error', 'error!') : null,
                );
            }
        }
        if ($items === []) {
            return null;
        }

        return new SearchGroup('projects', 'Projects', $items, count($items) + 10, '/projects?q=' . rawurlencode($query->text), 'See all');
    }
}
