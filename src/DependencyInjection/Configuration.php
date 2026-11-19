<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\DependencyInjection;

use Nowo\SearchKitBundle\Model\SearchFilter;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchPage;
use Nowo\SearchKitBundle\Search\MinLengthExemptPattern;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function is_bool;
use function is_scalar;
use function is_string;
use function sprintf;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

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

    /** The seven bundle locales (cross-locale keywords and synonyms, 1.8.0). */
    public const array LOCALES = ['en', 'es', 'it', 'fr', 'pt', 'de', 'nl'];

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
                ->arrayNode('min_length_exempt_patterns')
                    ->info('Regular expressions (no delimiters) in the subset PCRE and JavaScript read alike, e.g. "^#\\d+$". A shorter query matching one searches, on the providers implementing MinLengthExemptSearchProviderInterface only.')
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(static fn (mixed $pattern): bool => MinLengthExemptPattern::problem($pattern) !== null)
                            ->then(static function (mixed $pattern): never {
                                throw new InvalidConfigurationException(sprintf('Invalid nowo_search_kit.min_length_exempt_patterns entry %s: %s.', json_encode($pattern, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), MinLengthExemptPattern::problem($pattern)));
                            })
                        ->end()
                    ->end()
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
                ->scalarNode('toggle_icon_template')
                    ->info('Twig partial rendered inside the toggle instead of the three state icons (null keeps them).')
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(static fn (mixed $template): bool => $template !== null && (!is_string($template) || trim($template) === ''))
                        ->thenInvalid('nowo_search_kit.toggle_icon_template must be null or a Twig template path.')
                    ->end()
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
                        ->booleanNode('revalidate')
                            ->info('1.8.0: store references only (URL + kind, never titles) and re-check them on every display through POST {prefix}/recent (CSRF, rate-limited): the SearchAccessCheckerInterface must allow each URL and a SearchReferenceResolverInterface must resolve it, otherwise it is dropped.')
                            ->defaultFalse()
                        ->end()
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
                        ->arrayNode('keywords')
                            ->info('1.8.0: route name → translation key of comma-separated keywords (matched, never shown), read through the item getRouteName().')
                            ->useAttributeAsKey('route')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue([])
                        ->end()
                        ->scalarNode('keywords_translation_domain')
                            ->info('Domain of the "keywords" keys (null: the map holds plain keywords).')
                            ->defaultValue('messages')
                        ->end()
                        ->booleanNode('check_access')
                            ->info('1.8.0: also ask the SearchAccessCheckerInterface for every menu entry (deny by default). Off: the menu tree is trusted as before.')
                            ->defaultFalse()
                        ->end()
                        ->booleanNode('ranked')
                            ->info('1.8.0: score the rows (sorted by relevance with the registry pages). null: on when "pages" or "keywords" is configured.')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
            ->end();

        $this->addSearchEverywhere($treeBuilder);

        return $treeBuilder;
    }

    /**
     * The 1.8.0 keys (spec 240, "search everywhere"). Every default keeps a 1.7 host unchanged.
     */
    private function addSearchEverywhere(TreeBuilder $treeBuilder): void
    {
        $locales = static fn (string $info): mixed => (new TreeBuilder('locales', 'array'))->getRootNode()
            ->info($info)
            ->scalarPrototype()
                ->validate()
                    ->ifTrue(static fn (mixed $locale): bool => !is_string($locale) || preg_match('/^[a-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', $locale) !== 1)
                    ->thenInvalid('Invalid locale %s.')
                ->end()
            ->end()
            ->defaultValue(self::LOCALES);

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('pages')
                    ->info('1.8.0 page registry: pages, tabs and settings sections found by label, breadcrumb and keywords. Listed only when a SearchAccessCheckerInterface allows the route (deny by default).')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('id')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('label')->info('Translation key (plain text when translation_domain is null).')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('translation_domain')->defaultValue('messages')->end()
                            ->scalarNode('route')->isRequired()->cannotBeEmpty()->end()
                            ->arrayNode('route_parameters')
                                ->useAttributeAsKey('name')
                                ->variablePrototype()
                                    ->validate()
                                        ->ifTrue(static fn (mixed $value): bool => $value !== null && !is_scalar($value))
                                        ->thenInvalid('nowo_search_kit.pages route parameters must be scalars.')
                                    ->end()
                                ->end()
                            ->end()
                            ->arrayNode('trail')
                                ->info('Breadcrumb before the label (translation keys).')
                                ->beforeNormalization()->ifString()->then(static fn (string $crumb): array => [$crumb])->end()
                                ->scalarPrototype()->end()
                            ->end()
                            ->scalarNode('icon')->defaultNull()->end()
                            ->scalarNode('keywords')->info('Translation key of comma-separated keywords (one message per locale); matched, never shown.')->defaultNull()->end()
                            ->enumNode('scope')->values([SearchPage::SCOPE_NONE, SearchPage::SCOPE_REQUIRED])->defaultValue(SearchPage::SCOPE_NONE)->end()
                            ->scalarNode('scope_parameter')->info('With scope "required": the route parameter that receives the scope id.')->defaultNull()->end()
                            ->scalarNode('attribute')->info('Passed to the access checker (SearchAccessRequest::$attribute).')->defaultNull()->end()
                            ->scalarNode('subject')->info('Passed to the access checker (SearchAccessRequest::$subject).')->defaultNull()->end()
                        ->end()
                        ->validate()
                            ->ifTrue(static fn (array $page): bool => preg_match('/^[A-Za-z0-9._:-]{1,128}$/', (string) $page['id']) !== 1)
                            ->thenInvalid('A nowo_search_kit.pages "id" may only contain letters, digits, ".", "_", ":" and "-".')
                        ->end()
                        ->validate()
                            ->ifTrue(static fn (array $page): bool => $page['scope_parameter'] !== null && $page['scope'] !== SearchPage::SCOPE_REQUIRED)
                            ->thenInvalid('A nowo_search_kit.pages "scope_parameter" needs scope "required".')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('page_registry')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('priority')->info('Position of the registry among the providers (its rows join the Pages group).')->defaultValue(90)->end()
                        ->append($locales('Locales whose labels and keywords also match (cross-locale); the UI locale is always first.'))
                        ->integerNode('max_candidates')->info('Most entries read per search (configured, then page sources).')->defaultValue(500)->min(1)->max(10000)->end()
                        ->scalarNode('icon')->info('UX Icons name of an entry without an icon.')->defaultValue('tabler:file')->end()
                    ->end()
                ->end()
                ->arrayNode('access')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('filter_all_rows')
                            ->info('1.8.0: every provider row must also pass the SearchAccessCheckerInterface (by URL, deny by default) before it is counted, ranked or sent.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('actions')
                    ->info('1.8.0 palette actions (SearchActionProviderInterface). Without providers the quick_actions stay the empty-query list.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('prefix')
                            ->info('A query starting with it lists actions only ("" disables the prefix).')
                            ->defaultValue('>')
                            ->validate()
                                ->ifTrue(static fn (mixed $prefix): bool => !is_string($prefix) || mb_strlen($prefix) > 3 || preg_match('/[\s\p{L}\p{N}]/u', $prefix) === 1)
                                ->thenInvalid('nowo_search_kit.actions.prefix must be empty or up to 3 symbol characters.')
                            ->end()
                        ->end()
                        ->booleanNode('in_results')->info('Matching provider actions on top of the results of a normal query.')->defaultTrue()->end()
                        ->integerNode('max_candidates')->info('Most actions read per call.')->defaultValue(200)->min(1)->max(5000)->end()
                    ->end()
                ->end()
                ->arrayNode('filters')
                    ->info('1.8.0 typed filters (key:value, key:"quoted", -key:value).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('keys')
                            ->info('Recognised keys, e.g. [is, assignee, project, type]. Empty: no parsing (as before). "type" is handled by the engine.')
                            ->scalarPrototype()
                                ->validate()
                                    ->ifTrue(static fn (mixed $key): bool => !is_string($key) || preg_match(SearchFilter::KEY_PATTERN, $key) !== 1)
                                    ->thenInvalid('Invalid nowo_search_kit.filters.keys entry %s (lower-case letter first, then letters, digits, "_" or "-").')
                                ->end()
                            ->end()
                            ->defaultValue([])
                        ->end()
                        ->integerNode('max')->info('Most filters per query (extra ones are dropped).')->defaultValue(5)->min(1)->max(20)->end()
                        ->integerNode('values_limit')->info('Most autocomplete values per call.')->defaultValue(10)->min(1)->max(50)->end()
                    ->end()
                ->end()
                ->arrayNode('matching')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('fuzzy')
                            ->info('1.8.0: SearchQuery::matches() / score() tolerate typos (edit distance 1 for 4-6 letters, 2 from 7). Bounded: 64 characters, 8 words.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('synonyms')
                    ->info('1.8.0: expand the query with the SearchSynonymProviderInterface services before the providers run.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->info('Off: no expansion even with providers.')->defaultTrue()->end()
                        ->booleanNode('builtin_dictionary')->info('Register the bundle concept groups (en es it fr pt de nl).')->defaultFalse()->end()
                        ->append($locales('Locales asked for synonyms after the UI locale.'))
                        ->integerNode('max_terms')->info('Most alternatives per query.')->defaultValue(24)->min(1)->max(100)->end()
                    ->end()
                ->end()
                ->arrayNode('ranking')
                    ->info('1.8.0: rows with a score or a date are sorted inside their group (score + recency boost).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->floatNode('recency_weight')->defaultValue(0.25)->min(0.0)->max(10.0)->end()
                        ->floatNode('recency_half_life_days')->defaultValue(30.0)->min(0.01)->max(3650.0)->end()
                    ->end()
                ->end()
                ->arrayNode('groups')
                    ->info('1.9.0: per-group switches, by group key (e.g. tasks, crm_companies, pages): "enabled: false" skips the provider and drops the group; "priority" sorts the groups of an answer (highest first; a group without one counts as 0).')
                    ->useAttributeAsKey('key')
                    ->arrayPrototype()
                        ->children()
                            ->booleanNode('enabled')->defaultTrue()->end()
                            ->integerNode('priority')->defaultNull()->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static function (array $groups): bool {
                            foreach (array_keys($groups) as $key) {
                                if (preg_match('/^[a-z0-9_-]{1,64}$/', (string) $key) !== 1) {
                                    return true;
                                }
                            }

                            return false;
                        })
                        ->thenInvalid('nowo_search_kit.groups keys must be 1 to 64 characters of a-z, 0-9, "_" or "-".')
                    ->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('results_page')
                    ->info('1.8.0 full results page (route nowo_search_kit_results, {prefix}/results).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->scalarNode('template')->defaultValue('@NowoSearchKitBundle/results.html.twig')->cannotBeEmpty()->end()
                        ->scalarNode('layout')->info('Template the page extends (blocks "title" and "body"), e.g. your base layout.')->defaultValue('@NowoSearchKitBundle/results_layout.html.twig')->cannotBeEmpty()->end()
                        ->integerNode('per_group')->info('Rows per group on the "All" tab.')->defaultValue(10)->min(1)->max(50)->end()
                        ->integerNode('per_page')->info('Rows per page of one group.')->defaultValue(20)->min(1)->max(100)->end()
                        ->integerNode('top_results')->info('Rows of the Top results section (0: none).')->defaultValue(5)->min(0)->max(20)->end()
                        ->integerNode('max_page')->defaultValue(50)->min(1)->max(1000)->end()
                    ->end()
                ->end()
            ->end();
    }
}
