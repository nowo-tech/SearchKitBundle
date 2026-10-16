<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\DependencyInjection;

use Nowo\SearchKitBundle\Model\SearchItem;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function is_bool;
use function is_string;

/**
 * Configuration tree for `nowo_search_kit`.
 *
 * One search box per application, so the tree is flat (no `default_profile` / `profiles`: there is no second
 * variant to name, REQ-CFG-001 "only when multi-config makes sense"). Every key has a safe default; an empty
 * configuration gives a working, rate-limited endpoint for signed-in users.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class Configuration implements ConfigurationInterface
{
    public const string ALIAS = 'nowo_search_kit';

    /** Service id of DashboardMenuBundle's Twig extension, the default menu source of the Pages group. */
    public const string DASHBOARD_MENU_SERVICE = 'Nowo\\DashboardMenuBundle\\Twig\\MenuExtension';

    /**
     * Icon key → Symfony UX Icons name (Tabler set), used with `icon_set: ux_icon`. `search`, `close` and `loading`
     * are the toggle states; the others are row kinds (a group key is the default kind of its rows).
     */
    public const array DEFAULT_ICONS = [
        'search'  => 'tabler:search',
        'close'   => 'tabler:x',
        'loading' => 'tabler:loader-2',
        'pages'   => 'tabler:file',
        'recent'  => 'tabler:history',
        'action'  => 'tabler:arrow-right',
        'item'    => 'tabler:point',
    ];

    /**
     * Builds the `nowo_search_kit` tree.
     *
     * @return TreeBuilder The configuration tree
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('role')
                    ->info('Attribute the user must be granted to see the search box and call the endpoint.')
                    ->defaultValue('IS_AUTHENTICATED')
                    ->cannotBeEmpty()
                ->end()
                ->integerNode('min_length')
                    ->info('Shorter queries return no groups (the box shows recents and quick actions instead).')
                    ->defaultValue(2)
                    ->min(1)
                    ->max(50)
                ->end()
                ->integerNode('max_length')
                    ->info('Longer queries are cut to this many characters.')
                    ->defaultValue(200)
                    ->min(10)
                    ->max(1000)
                ->end()
                ->integerNode('per_group')
                    ->info('Rows per group (SearchQuery::$limit); a provider returning more is cut to it.')
                    ->defaultValue(5)
                    ->min(1)
                    ->max(50)
                ->end()
                ->integerNode('debounce_ms')
                    ->info('Delay after the last keystroke before the browser asks the endpoint.')
                    ->defaultValue(150)
                    ->min(0)
                    ->max(2000)
                ->end()
                ->booleanNode('keyboard_shortcuts')
                    ->info('Open the search with Ctrl/⌘ K anywhere and "/" outside form fields.')
                    ->defaultTrue()
                ->end()
                ->arrayNode('rate_limit')
                    ->info('Per-user limiter of the endpoint (framework rate_limiter `nowo_search_kit`); 429 when exceeded.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->enumNode('policy')
                            ->values(['sliding_window', 'fixed_window', 'token_bucket'])
                            ->defaultValue('sliding_window')
                        ->end()
                        ->integerNode('limit')->defaultValue(30)->min(1)->end()
                        ->scalarNode('interval')
                            ->defaultValue('10 seconds')
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('recent')
                    ->info('Recent picks remembered per browser (localStorage; title, subtitle, URL and kind only — never HTML).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->integerNode('max')->defaultValue(8)->min(1)->max(50)->end()
                        ->scalarNode('storage_key')
                            ->defaultValue('nowo.search.recent')
                            ->cannotBeEmpty()
                            ->validate()
                                ->ifTrue(static fn (mixed $key): bool => preg_match('/^[A-Za-z0-9._:-]+$/', (string) $key) !== 1)
                                ->thenInvalid('nowo_search_kit.recent.storage_key may only contain letters, digits, ".", "_", ":" and "-".')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('quick_actions')
                    ->info('Rows shown while the query is empty (after the recents). A host can pass more to the template.')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('label')
                                ->info('Translation key (or plain text) of the row.')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('translation_domain')->defaultValue('messages')->end()
                            ->scalarNode('route')->defaultNull()->end()
                            ->arrayNode('route_parameters')
                                ->useAttributeAsKey('name')
                                ->scalarPrototype()->end()
                            ->end()
                            ->scalarNode('url')->defaultNull()->end()
                            ->scalarNode('kind')->defaultValue('action')->cannotBeEmpty()->end()
                            ->scalarNode('role')
                                ->info('Only listed when the user is granted this attribute.')
                                ->defaultNull()
                            ->end()
                        ->end()
                        ->validate()
                            ->ifTrue(static fn (array $action): bool => ($action['route'] === null) === ($action['url'] === null))
                            ->thenInvalid('Each nowo_search_kit.quick_actions entry needs exactly one of "route" or "url".')
                        ->end()
                        ->validate()
                            ->ifTrue(static fn (array $action): bool => $action['url'] !== null && !SearchItem::isSafeUrl((string) $action['url']))
                            ->thenInvalid('A nowo_search_kit.quick_actions "url" must be a path starting with "/" or an http(s) URL.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('assets')
                    ->info('Tags the header template prints. Turn them off when your bundler ships the CSS / JS itself.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('stylesheet')
                            ->info('nowo-search.css (the field, always) and the lazy nowo-search-popover.css (dropdown).')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('script')
                            ->info('nowo-search.js (IIFE that mounts every search box).')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('popover_stylesheet')
                            ->info('Dropdown stylesheet injected on the first hover / focus. null: the bundle file when "stylesheet" is on; false: none (load it yourself, e.g. the JS loadPopoverStyles option); a path or http(s) URL: that file (e.g. your bundler chunk).')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => $value === true || (is_string($value) && !SearchItem::isSafeUrl($value)) || (!is_string($value) && !is_bool($value) && $value !== null))
                                ->thenInvalid('nowo_search_kit.assets.popover_stylesheet must be null, false, a path starting with "/" or an http(s) URL.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->enumNode('icon_set')
                    ->info('ux_icon: Symfony UX Icons with the "icons" map (default); svg_inline: the built-in SVG only.')
                    ->values(['ux_icon', 'svg_inline'])
                    ->defaultValue('ux_icon')
                ->end()
                ->arrayNode('icons')
                    ->info('UX Icons name per icon key / row kind (merged over the defaults). An icon UX Icons cannot render falls back to the built-in SVG.')
                    ->useAttributeAsKey('name')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('menu_pages')
                    ->info('Built-in "Pages" group: menu entries the user can see, from nowo-tech/dashboard-menu-bundle.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('null: on when DashboardMenuBundle is installed and "menus" is not empty.')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('service')
                            ->info('Service exposing getMenuTree(string $code) and getHref($item, int $referenceType).')
                            ->defaultValue(self::DASHBOARD_MENU_SERVICE)
                            ->cannotBeEmpty()
                        ->end()
                        ->arrayNode('menus')
                            ->info('Menu codes to walk, in order; "label" (translation key) starts the breadcrumb of their rows.')
                            ->arrayPrototype()
                                ->beforeNormalization()
                                    ->ifString()
                                    ->then(static fn (string $code): array => ['code' => $code])
                                ->end()
                                ->children()
                                    ->scalarNode('code')->isRequired()->cannotBeEmpty()->end()
                                    ->scalarNode('label')->defaultNull()->end()
                                    ->scalarNode('translation_domain')->defaultValue('messages')->end()
                                ->end()
                            ->end()
                        ->end()
                        ->scalarNode('icon')
                            ->info('UX Icons name of a page without an icon of its own.')
                            ->defaultValue('tabler:file')
                        ->end()
                        ->integerNode('priority')
                            ->info('Position among the providers (higher first).')
                            ->defaultValue(100)
                        ->end()
                        ->booleanNode('skip_when_scoped')
                            ->info('No Pages group while the search is scoped (SearchQuery::$scope set).')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
