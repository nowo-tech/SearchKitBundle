<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\DependencyInjection;

use Nowo\SearchKitBundle\Contract\SearchAccessCheckerInterface;
use Nowo\SearchKitBundle\Contract\SearchActionProviderInterface;
use Nowo\SearchKitBundle\Contract\SearchFilterValuesProviderInterface;
use Nowo\SearchKitBundle\Contract\SearchPageSourceInterface;
use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Contract\SearchReferenceResolverInterface;
use Nowo\SearchKitBundle\Contract\SearchSynonymProviderInterface;
use Nowo\SearchKitBundle\NowoSearchKitBundle;
use Nowo\SearchKitBundle\Provider\MenuPagesProvider;
use Nowo\SearchKitBundle\Search\Access\SearchAccess;
use Nowo\SearchKitBundle\Search\Page\PageRegistryProvider;
use Nowo\SearchKitBundle\Search\SearchEngine;
use Nowo\SearchKitBundle\Search\Synonym\ConceptGroupSynonymProvider;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function array_key_exists;
use function is_array;

/**
 * Loads `nowo_search_kit`, registers the asset package and the rate limiter, tags the providers and wires the
 * optional Pages provider.
 *
 * Container parameters: `nowo_search_kit.{role,min_length,min_length_exempt_patterns,max_length,per_group,icon_set,toggle_icon_template,icons,settings}`
 * and, 1.8.0, `nowo_search_kit.{pages,page_registry,access,actions,filters,matching,synonyms,ranking,results_page,recent}`.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoSearchKitExtension extends Extension implements PrependExtensionInterface
{
    /** Public path created by `assets:install` for this bundle (REQ-ASSETS-004). */
    public const string ASSET_BASE_PATH = '/bundles/nowosearchkit';

    /** Framework rate limiter name (service `limiter.nowo_search_kit`). */
    public const string LIMITER = 'nowo_search_kit';

    /**
     * Registers the `nowo_search_kit` asset package and, when enabled, the `nowo_search_kit` rate limiter.
     *
     * @param ContainerBuilder $container The container being built
     */
    public function prepend(ContainerBuilder $container): void
    {
        $this->prependFormKitProfile($container);
        if (!$container->hasExtension('framework')) {
            return;
        }
        $container->prependExtensionConfig('framework', [
            'assets' => ['packages' => [Configuration::ALIAS => ['base_path' => self::ASSET_BASE_PATH]]],
        ]);

        /** @var array{rate_limit: array{enabled: bool, policy: string, limit: int, interval: string}} $config */
        $config = (new Processor())->processConfiguration(new Configuration(), $this->rateLimitConfigs($container));
        $limit  = $config['rate_limit'];
        if (!$limit['enabled']) {
            return;
        }
        $limiter = ['policy' => $limit['policy'], 'limit' => $limit['limit']];
        if ($limit['policy'] === 'token_bucket') {
            $limiter['rate'] = ['interval' => $limit['interval'], 'amount' => $limit['limit']];
        } else {
            $limiter['interval'] = $limit['interval'];
        }
        $container->prependExtensionConfig('framework', ['rate_limiter' => [self::LIMITER => $limiter]]);
    }

    /**
     * Default FormKit profile of the bundle form: translation domain, no auto help / placeholder convention keys.
     * REQ-UI-003: no framework classes; the type keeps `nowo-search__input` / `data-part` and UiKit theme slots add
     * host classes.
     *
     * @return array<string, mixed>
     */
    public static function formKitProfile(): array
    {
        return [
            'alias'              => NowoSearchKitBundle::FORM_KIT_PROFILE,
            'translation_domain' => NowoSearchKitBundle::TRANSLATION_DOMAIN,
            'auto_help'          => false,
            'auto_placeholder'   => false,
            'defaults'           => [
                'attr'     => [],
                'row_attr' => [],
            ],
            'field_types' => [],
        ];
    }

    /**
     * Registers the FormKit profile {@see NowoSearchKitBundle::FORM_KIT_PROFILE} used by `SearchFieldType`
     * (#[FormKitConfig]). A host overrides it by defining `nowo_form_kit.profiles.search_kit` itself (nothing is
     * prepended then). Does not change the host `default_profile`.
     */
    private function prependFormKitProfile(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_form_kit')) {
            return;
        }

        foreach ($container->getExtensionConfig('nowo_form_kit') as $config) {
            $profiles = $config['profiles'] ?? null;
            if (is_array($profiles) && array_key_exists(NowoSearchKitBundle::FORM_KIT_PROFILE, $profiles)) {
                return;
            }
        }

        $container->prependExtensionConfig('nowo_form_kit', [
            'profiles' => [NowoSearchKitBundle::FORM_KIT_PROFILE => self::formKitProfile()],
        ]);
    }

    /**
     * Processes the configuration and loads `Resources/config/services.php`.
     *
     * @param array<array<string, mixed>> $configs Raw `nowo_search_kit` configs
     * @param ContainerBuilder $container The container being built
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{role: string, min_length: int, min_length_exempt_patterns: list<string>, max_length: int, per_group: int, debounce_ms: int, keyboard_shortcuts: bool, rate_limit: array{enabled: bool}, recent: array{enabled: bool, revalidate: bool, max: int, storage_key: string}, quick_actions: list<array<string, mixed>>, assets: array{stylesheet: bool, script: bool}, icon_set: string, toggle_icon_template: string|null, icons: array<string, string>, menu_pages: array{enabled: bool|null, service: string, menus: list<array{code: string, label: string|null, translation_domain: string|null}>, icon: string, priority: int, skip_when_scoped: bool, keywords: array<string, string>, keywords_translation_domain: string|null, check_access: bool, ranked: bool|null}, pages: list<array<string, mixed>>, page_registry: array{priority: int, locales: list<string>, max_candidates: int, icon: string}, access: array{filter_all_rows: bool}, actions: array{prefix: string, in_results: bool, max_candidates: int}, filters: array{keys: list<string>, max: int, values_limit: int}, matching: array{fuzzy: bool}, synonyms: array{enabled: bool, builtin_dictionary: bool, locales: list<string>, max_terms: int}, ranking: array{recency_weight: float, recency_half_life_days: float}, results_page: array<string, mixed>} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        // A map written in one file keeps its keys and would reach the browser as an object: a list, always.
        $config['min_length_exempt_patterns'] = array_values($config['min_length_exempt_patterns']);

        // The tree validated the literal placeholder: the value read at runtime would escape the check.
        foreach ($config['min_length_exempt_patterns'] as $pattern) {
            $usedEnvs = [];
            $container->resolveEnvPlaceholders($pattern, null, $usedEnvs);
            if ($usedEnvs !== []) {
                throw new InvalidConfigurationException('nowo_search_kit.min_length_exempt_patterns refuses %env()% values: a pattern must be validated at container compilation, so write it in the configuration.');
            }
        }

        // Lists, whatever the configuration format wrote (a YAML map would reach the services keyed).
        $config['pages']                    = array_values($config['pages']);
        $config['filters']['keys']          = array_values(array_unique($config['filters']['keys']));
        $config['page_registry']['locales'] = array_values($config['page_registry']['locales']);
        $config['synonyms']['locales']      = array_values($config['synonyms']['locales']);
        foreach ($config['pages'] as $index => $page) {
            $config['pages'][$index]['trail'] = array_values((array) $page['trail']);
        }

        foreach (['role', 'min_length', 'min_length_exempt_patterns', 'max_length', 'per_group', 'icon_set', 'toggle_icon_template', 'pages', 'quick_actions', 'results_page'] as $key) {
            $container->setParameter(Configuration::ALIAS . '.' . $key, $config[$key]);
        }
        // 1.8.0 groups: one parameter per key (nowo_search_kit.filters.keys, …), read by services.php.
        foreach (['page_registry', 'access', 'actions', 'filters', 'matching', 'synonyms', 'ranking'] as $group) {
            foreach ($config[$group] as $key => $value) {
                $container->setParameter(Configuration::ALIAS . '.' . $group . '.' . $key, $value);
            }
        }
        $revalidate = $config['recent']['enabled'] && $config['recent']['revalidate'];
        $container->setParameter(Configuration::ALIAS . '.recent.revalidate', $revalidate);
        $container->setParameter(Configuration::ALIAS . '.recent.max', $config['recent']['max']);
        $container->setParameter(Configuration::ALIAS . '.engine_options', [
            'fuzzy'              => $config['matching']['fuzzy'],
            'filter_all_rows'    => $config['access']['filter_all_rows'],
            'action_prefix'      => $config['actions']['prefix'],
            'actions_in_results' => $config['actions']['in_results'],
        ]);
        $container->setParameter(Configuration::ALIAS . '.features', [
            'action_prefix' => $config['actions']['prefix'],
            'filter_keys'   => $config['filters']['keys'],
            'results'       => (bool) $config['results_page']['enabled'],
            'revalidate'    => $revalidate,
        ]);
        $container->setParameter(Configuration::ALIAS . '.icons', array_replace(Configuration::DEFAULT_ICONS, $config['icons']));
        $container->setParameter(Configuration::ALIAS . '.settings', array_intersect_key($config, array_flip([
            'role', 'min_length', 'min_length_exempt_patterns', 'max_length', 'debounce_ms', 'keyboard_shortcuts', 'recent', 'quick_actions', 'assets',
        ])));

        $container->registerForAutoconfiguration(SearchProviderInterface::class)->addTag(SearchProviderInterface::TAG);
        $container->registerForAutoconfiguration(SearchAccessCheckerInterface::class)->addTag(SearchAccessCheckerInterface::ACCESS_CHECKER_TAG);
        $container->registerForAutoconfiguration(SearchPageSourceInterface::class)->addTag(SearchPageSourceInterface::PAGE_SOURCE_TAG);
        $container->registerForAutoconfiguration(SearchActionProviderInterface::class)->addTag(SearchActionProviderInterface::ACTION_PROVIDER_TAG);
        $container->registerForAutoconfiguration(SearchFilterValuesProviderInterface::class)->addTag(SearchFilterValuesProviderInterface::FILTER_VALUES_TAG);
        $container->registerForAutoconfiguration(SearchSynonymProviderInterface::class)->addTag(SearchSynonymProviderInterface::SYNONYM_PROVIDER_TAG);
        $container->registerForAutoconfiguration(SearchReferenceResolverInterface::class)->addTag(SearchReferenceResolverInterface::REFERENCE_RESOLVER_TAG);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.php');

        $container->getDefinition(PageRegistryProvider::class)
            ->addTag(SearchProviderInterface::TAG, ['priority' => $config['page_registry']['priority']]);
        if (!$config['synonyms']['enabled']) {
            $container->getDefinition(SearchEngine::class)->replaceArgument(6, null);
        }
        if ($config['synonyms']['builtin_dictionary']) {
            $container->register(ConceptGroupSynonymProvider::class, ConceptGroupSynonymProvider::class)
                ->addTag(SearchSynonymProviderInterface::SYNONYM_PROVIDER_TAG);
        }

        $pages   = $config['menu_pages'];
        $enabled = $pages['enabled'] ?? ($pages['menus'] !== [] && class_exists($pages['service']));
        if ($enabled) {
            $container->register(MenuPagesProvider::class, MenuPagesProvider::class)
                ->setArguments([
                    new Reference($pages['service']),
                    new Reference('translator'),
                    $pages['menus'],
                    $pages['icon'],
                    $pages['skip_when_scoped'],
                    $pages['keywords'],
                    $pages['keywords_translation_domain'],
                    $pages['check_access'] ? new Reference(SearchAccess::class) : null,
                    $pages['ranked'] ?? ($config['pages'] !== [] || $pages['keywords'] !== []),
                ])
                ->addTag(SearchProviderInterface::TAG, ['priority' => $pages['priority']])
                ->addTag(SearchReferenceResolverInterface::REFERENCE_RESOLVER_TAG, ['priority' => 90]);
        }
    }

    /**
     * Returns the configuration alias (`nowo_search_kit`).
     *
     * @return string The alias
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * The raw configs with only `rate_limit` (other keys may hold %env()% values, resolved later in load()).
     *
     * @return list<array<string, mixed>>
     */
    private function rateLimitConfigs(ContainerBuilder $container): array
    {
        $configs = [];
        foreach ($container->getExtensionConfig(Configuration::ALIAS) as $config) {
            $configs[] = array_intersect_key($config, ['rate_limit' => true]);
        }

        return $configs;
    }
}
