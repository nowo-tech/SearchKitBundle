<?php

declare(strict_types=1);

namespace App\Search;

use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Model\SearchBadge;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Projects" group: only the projects the user may open; a scope narrows to that project (an inaccessible one
 * yields nothing — never a 403).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[AsTaggedItem(priority: 50)]
final readonly class ProjectProvider implements SearchProviderInterface
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private RequestStack $requests,
    ) {
    }

    public function search(SearchQuery $query): ?SearchGroup
    {
        $projects = DemoData::projectsFor($query->user);
        if ($query->scope !== null) {
            $projects = array_intersect_key($projects, [$query->scope => true]);
        }
        $locale  = $this->requests->getCurrentRequest()?->getLocale() ?? 'en';
        $matches = array_filter($projects, static fn (array $p, string $id): bool => $query->matches($p[0], $p[1], $id), \ARRAY_FILTER_USE_BOTH);
        $items   = [];
        foreach (\array_slice($matches, 0, $query->limit, true) as $id => [$name, $description, $members]) {
            $items[] = new SearchItem(
                $name,
                $this->urls->generate('demo_project', ['_locale' => $locale, 'id' => $id]),
                $description,
                'tabler:folder',
                $members === [] ? null : new SearchBadge($this->translator->trans('demo.badge.private'), 'warning'),
            );
        }

        return new SearchGroup('projects', $this->translator->trans('demo.group.projects'), $items, \count($matches));
    }
}
