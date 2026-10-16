<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\DependencyInjection;

use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Provider\MenuPagesProvider;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Loads `nowo_search_kit`, registers the asset package and the rate limiter, tags the providers and wires the
 * optional Pages provider.
 *
 * Container parameters: `nowo_search_kit.{role,min_length,max_length,per_group,icon_set,icons,settings}`.
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
     * Processes the configuration and loads `Resources/config/services.php`.
     *
     * @param array<array<string, mixed>> $configs Raw `nowo_search_kit` configs
     * @param ContainerBuilder $container The container being built
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{role: string, min_length: int, max_length: int, per_group: int, debounce_ms: int, keyboard_shortcuts: bool, rate_limit: array{enabled: bool}, recent: array<string, mixed>, quick_actions: list<array<string, mixed>>, assets: array{stylesheet: bool, script: bool}, icon_set: string, icons: array<string, string>, menu_pages: array{enabled: bool|null, service: string, menus: list<array{code: string, label: string|null, translation_domain: string|null}>, icon: string, priority: int, skip_when_scoped: bool}} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        foreach (['role', 'min_length', 'max_length', 'per_group', 'icon_set'] as $key) {
            $container->setParameter(Configuration::ALIAS . '.' . $key, $config[$key]);
        }
        $container->setParameter(Configuration::ALIAS . '.icons', array_replace(Configuration::DEFAULT_ICONS, $config['icons']));
        $container->setParameter(Configuration::ALIAS . '.settings', array_intersect_key($config, array_flip([
            'role', 'min_length', 'max_length', 'debounce_ms', 'keyboard_shortcuts', 'recent', 'quick_actions', 'assets',
        ])));

        $container->registerForAutoconfiguration(SearchProviderInterface::class)->addTag(SearchProviderInterface::TAG);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.php');

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
                ])
                ->addTag(SearchProviderInterface::TAG, ['priority' => $pages['priority']]);
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
