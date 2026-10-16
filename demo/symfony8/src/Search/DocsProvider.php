<?php

declare(strict_types=1);

namespace App\Search;

use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Documentation" group (public pages), with a "See all" link to the docs index. Hidden while scoped to a project.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[AsTaggedItem(priority: 40)]
final readonly class DocsProvider implements SearchProviderInterface
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private RequestStack $requests,
    ) {
    }

    public function search(SearchQuery $query): ?SearchGroup
    {
        if ($query->isScoped()) {
            return null;
        }
        $locale  = $this->requests->getCurrentRequest()?->getLocale() ?? 'en';
        $matches = array_filter(DemoData::DOCS, static fn (array $doc): bool => $query->matches(...$doc));
        $items   = [];
        foreach ($matches as $slug => [$title, $summary]) {
            $items[] = new SearchItem($title, $this->urls->generate('demo_doc', ['_locale' => $locale, 'slug' => $slug]), $summary, 'tabler:book');
        }

        return new SearchGroup(
            'docs',
            $this->translator->trans('demo.group.docs'),
            $items,
            \count($matches),
            \count($matches) > $query->limit ? $this->urls->generate('demo_doc', ['_locale' => $locale, 'slug' => 'installation']) : null,
            $this->translator->trans('demo.see_all_docs'),
        );
    }
}
