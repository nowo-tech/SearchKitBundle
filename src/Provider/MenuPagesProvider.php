<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Provider;

use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_slice;
use function count;
use function is_array;
use function is_object;
use function is_string;

/**
 * Built-in "Pages" group: the menu entries the user can see whose label contains the query.
 *
 * Reads the menus through any service exposing `getMenuTree(string $code): list<array{item: object, children?: list}>`
 * and `getHref(object $item, int $referenceType): string` — by default DashboardMenuBundle's Twig extension, whose
 * tree is already filtered by the menu permission checkers (so a page the user cannot open is never listed). Items
 * need `getLabel(): string` and may have `getIcon(): ?string`. Rows carry a breadcrumb (menu label › parents), use
 * absolute paths, and a page reachable from several menus is listed once (deduplicated by URL).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class MenuPagesProvider implements SearchProviderInterface
{
    public const string KEY = 'pages';

    /**
     * @param object $menus Menu source (see class description)
     * @param TranslatorInterface $translator Translates the group heading and the menu labels
     * @param list<array{code: string, label: string|null, translation_domain: string|null}> $menuCodes Menus to walk, in order
     * @param string $defaultIcon UX Icons name of a page without an icon
     * @param bool $skipWhenScoped No group while the search is scoped
     */
    public function __construct(
        private object $menus,
        private TranslatorInterface $translator,
        private array $menuCodes = [],
        private string $defaultIcon = 'tabler:file',
        private bool $skipWhenScoped = true,
    ) {
    }

    /**
     * Searches the menu labels.
     *
     * @param SearchQuery $query The query
     *
     * @return SearchGroup|null The Pages group, or null when scoped (skip_when_scoped) or the source has no menu API
     */
    public function search(SearchQuery $query): ?SearchGroup
    {
        if (($this->skipWhenScoped && $query->isScoped()) || !method_exists($this->menus, 'getMenuTree') || !method_exists($this->menus, 'getHref')) {
            return null;
        }

        $found = [];
        foreach ($this->menuCodes as $menu) {
            $trail = $menu['label'] !== null ? $this->translator->trans($menu['label'], [], $menu['translation_domain']) : '';
            $tree  = $this->menus->getMenuTree($menu['code']);
            $this->collect(is_array($tree) ? $tree : [], $query, $trail, $found);
        }

        return new SearchGroup(
            self::KEY,
            $this->translator->trans('search.group.pages', [], 'NowoSearchKitBundle'),
            array_slice(array_values($found), 0, $query->limit),
            count($found),
        );
    }

    /**
     * Walks one level of the tree, depth first.
     *
     * @param array<mixed> $nodes Menu tree nodes
     * @param array<string, SearchItem> $found Matches keyed by URL
     */
    private function collect(array $nodes, SearchQuery $query, string $trail, array &$found): void
    {
        foreach ($nodes as $node) {
            $item = is_array($node) ? ($node['item'] ?? null) : null;
            if (!is_object($item) || !method_exists($item, 'getLabel')) {
                continue;
            }
            $label = trim((string) $item->getLabel());
            $href  = method_exists($this->menus, 'getHref') ? (string) $this->menus->getHref($item, UrlGeneratorInterface::ABSOLUTE_PATH) : '';
            if ($label !== '' && !isset($found[$href]) && SearchItem::isSafeUrl($href) && $query->matches($label)) {
                $icon         = method_exists($item, 'getIcon') ? $item->getIcon() : null;
                $found[$href] = new SearchItem($label, $href, $trail, is_string($icon) && $icon !== '' ? $icon : $this->defaultIcon);
            }
            $children = $node['children'] ?? [];
            $this->collect(is_array($children) ? $children : [], $query, $label === '' ? $trail : ($trail === '' ? $label : $trail . ' › ' . $label), $found);
        }
    }
}
