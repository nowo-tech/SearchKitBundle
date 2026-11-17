<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Provider;

use Nowo\SearchKitBundle\Contract\MenuTreeSourceInterface;
use Nowo\SearchKitBundle\Contract\SearchKeywordsAwareInterface;
use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Contract\SearchReferenceResolverInterface;
use Nowo\SearchKitBundle\Model\SearchAccessRequest;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Search\Access\SearchAccess;
use Nowo\SearchKitBundle\Search\Page\PageRegistryProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_slice;
use function count;
use function is_array;
use function is_object;
use function is_string;

/**
 * Built-in "Pages" group: the menu entries the user can see whose label (or keywords, 1.8.0) match the query.
 *
 * Reads the menus through a MenuTreeSourceInterface service (1.7.0), or any service exposing the same
 * `getMenuTree(string $code): list<array{item: object, children?: list}>` and
 * `getHref(object $item, int $referenceType): string` methods — by default DashboardMenuBundle's Twig extension, whose
 * tree is already filtered by the menu permission checkers (so a page the user cannot open is never listed). Items
 * need `getLabel(): string` and may have `getIcon(): ?string`. Rows carry a breadcrumb (menu label › parents), use
 * absolute paths, and a page reachable from several menus is listed once (deduplicated by URL).
 *
 * 1.8.0: keywords (never shown) come from `menu_pages.keywords` (route → translation key, read through the item's
 * `getRouteName()`) and from an item's `getSearchKeywords()` (SearchKeywordsAwareInterface or duck typing); with
 * `menu_pages.check_access` every entry also needs the SearchAccessCheckerInterface (deny by default) before it is
 * matched; with `menu_pages.ranked` rows carry a score (sorted with the registry pages). It also resolves recent
 * references to menu entries.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class MenuPagesProvider implements SearchProviderInterface, SearchReferenceResolverInterface
{
    public const string KEY = 'pages';

    /**
     * @param MenuTreeSourceInterface|object $menus Menu source (see class description)
     * @param TranslatorInterface $translator Translates the group heading and the menu labels
     * @param list<array{code: string, label: string|null, translation_domain: string|null}> $menuCodes Menus to walk, in order
     * @param string $defaultIcon UX Icons name of a page without an icon
     * @param bool $skipWhenScoped No group while the search is scoped
     * @param array<string, string> $keywords Route name → keywords translation key (1.8.0)
     * @param string|null $keywordsDomain Domain of the keywords keys (null: the map holds plain keywords)
     * @param SearchAccess|null $access Access checkers (1.8.0); null: the menu tree is trusted as before
     * @param bool $ranked Give rows a score (1.8.0), so the engine sorts them by relevance
     */
    public function __construct(
        private object $menus,
        private TranslatorInterface $translator,
        private array $menuCodes = [],
        private string $defaultIcon = 'tabler:file',
        private bool $skipWhenScoped = true,
        private array $keywords = [],
        private ?string $keywordsDomain = 'messages',
        private ?SearchAccess $access = null,
        private bool $ranked = false,
    ) {
    }

    /**
     * Searches the menu labels and keywords.
     *
     * @param SearchQuery $query The query
     *
     * @return SearchGroup|null The Pages group, or null when scoped (skip_when_scoped) or the source has no menu API
     */
    public function search(SearchQuery $query): ?SearchGroup
    {
        if (($this->skipWhenScoped && $query->isScoped()) || !$this->hasMenuApi()) {
            return null;
        }

        $found = [];
        $this->walk(function (object $item, string $label, string $href, string $trail) use ($query, &$found): void {
            if (isset($found[$href]) || !$this->allowed($href, $query->userIdentifier, $query->scope, SearchAccessRequest::KIND_PAGE)) {
                return;
            }
            $score    = $query->score($label);
            $keywords = $this->keywordsOf($item);
            if ($keywords !== []) {
                $keywordScore = $query->score(...$keywords);
                $score        = $keywordScore !== null && ($score === null || $keywordScore * 0.85 > $score) ? $keywordScore * 0.85 : $score;
            }
            if ($score !== null) {
                $found[$href] = $this->row($item, $label, $href, $trail, $this->ranked ? $score : null);
            }
        });

        return new SearchGroup(
            self::KEY,
            $this->translator->trans('search.group.pages', [], 'NowoSearchKitBundle'),
            // Ranked rows go to the engine whole: it sorts them before the per-group cut.
            array_slice(array_values($found), 0, $this->ranked ? count($found) : $query->limit),
            count($found),
            null,
            null,
            // The tree holds only the entries the viewer may open, so the count is exact for this viewer.
            true,
        );
    }

    /**
     * The row of a recent reference to a menu entry (same URL) that the menu still lists for the viewer.
     */
    public function resolve(SearchReference $reference, string $userIdentifier, ?string $locale): ?SearchItem
    {
        if (!$this->hasMenuApi()) {
            return null;
        }
        $found = null;
        $this->walk(function (object $item, string $label, string $href, string $trail) use ($reference, &$found): void {
            if (!$found instanceof SearchItem && $href === $reference->url) {
                $found = $this->row($item, $label, $href, $trail, null);
            }
        });

        return $found;
    }

    /**
     * Whether the source is a MenuTreeSourceInterface, or exposes both of its methods (duck typing, before 1.7.0).
     */
    private function hasMenuApi(): bool
    {
        return $this->menus instanceof MenuTreeSourceInterface
            || (method_exists($this->menus, 'getMenuTree') && method_exists($this->menus, 'getHref'));
    }

    /**
     * Calls $visit for every labelled entry with a safe URL, depth first, menu after menu.
     *
     * @param callable(object, string, string, string): void $visit Item, label, URL, breadcrumb
     */
    private function walk(callable $visit): void
    {
        foreach ($this->menuCodes as $menu) {
            $trail = $menu['label'] !== null ? $this->translator->trans($menu['label'], [], $menu['translation_domain']) : '';
            $tree  = $this->menus instanceof MenuTreeSourceInterface || method_exists($this->menus, 'getMenuTree') ? $this->menus->getMenuTree($menu['code']) : [];
            $this->collect(is_array($tree) ? $tree : [], $trail, $visit);
        }
    }

    /**
     * Walks one level of the tree, depth first.
     *
     * @param array<mixed> $nodes Menu tree nodes
     * @param callable(object, string, string, string): void $visit
     */
    private function collect(array $nodes, string $trail, callable $visit): void
    {
        foreach ($nodes as $node) {
            $item = is_array($node) ? ($node['item'] ?? null) : null;
            if (!is_object($item) || !method_exists($item, 'getLabel')) {
                continue;
            }
            $label = trim((string) $item->getLabel());
            $href  = $this->menus instanceof MenuTreeSourceInterface || method_exists($this->menus, 'getHref') ? (string) $this->menus->getHref($item, UrlGeneratorInterface::ABSOLUTE_PATH) : '';
            if ($label !== '' && SearchItem::isSafeUrl($href)) {
                $visit($item, $label, $href, $trail);
            }
            $children = $node['children'] ?? [];
            $this->collect(is_array($children) ? $children : [], $label === '' ? $trail : ($trail === '' ? $label : $trail . ' › ' . $label), $visit);
        }
    }

    private function row(object $item, string $label, string $href, string $trail, ?float $score): SearchItem
    {
        $icon = method_exists($item, 'getIcon') ? $item->getIcon() : null;

        return new SearchItem($label, $href, $trail, is_string($icon) && $icon !== '' ? $icon : $this->defaultIcon, null, null, $score);
    }

    /**
     * With check_access, the access checkers decide (deny by default); otherwise the filtered menu tree is trusted.
     */
    private function allowed(string $href, string $userIdentifier, ?string $scope, string $kind): bool
    {
        return !$this->access instanceof SearchAccess || $this->access->allowsUrl($href, $userIdentifier, $kind, $scope);
    }

    /**
     * Keywords of an item: its own (`getSearchKeywords()`) and those of its route in `menu_pages.keywords`.
     *
     * @return list<string>
     */
    private function keywordsOf(object $item): array
    {
        $raw = [];
        if ($item instanceof SearchKeywordsAwareInterface || method_exists($item, 'getSearchKeywords')) {
            $own   = $item->getSearchKeywords();
            $raw[] = is_string($own) ? $own : '';
        }
        $route = $this->keywords !== [] && method_exists($item, 'getRouteName') ? $item->getRouteName() : null;
        if (is_string($route) && isset($this->keywords[$route])) {
            $key     = $this->keywords[$route];
            $message = $this->keywordsDomain === null ? $key : $this->translator->trans($key, [], $this->keywordsDomain);
            $raw[]   = $message === $key && $this->keywordsDomain !== null ? '' : $message;
        }

        // Bounded like the registry (audit SP-M1): at most 32 keywords per entry.
        return array_slice(array_values(array_filter(array_map(trim(...), explode(',', implode(',', $raw))), static fn (string $word): bool => $word !== '')), 0, PageRegistryProvider::MAX_KEYWORDS);
    }
}
