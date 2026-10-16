<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support;

use Nowo\SearchKitBundle\Contract\SearchScopeResolverInterface;
use Nowo\SearchKitBundle\NowoSearchKitBundle;
use Nowo\SearchKitBundle\Tests\Support\Provider\FailingProvider;
use Nowo\SearchKitBundle\Tests\Support\Provider\FakeMenu;
use Nowo\SearchKitBundle\Tests\Support\Provider\ProjectsProvider;
use Nowo\SearchKitBundle\Tests\Support\Provider\TestScopeResolver;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\Icons\UXIconsBundle;
use Twig\Environment;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;

/**
 * Kernel for integration and functional tests: in-memory users (alice, bob, admin), the test providers, a fake
 * menu source and the bundle routes under /search.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const string ASSET_PACKAGES_ALIAS = 'nowo_search_kit_test.assets_packages';

    /**
     * @param array<string, mixed> $searchKitConfig Merged over the default test configuration
     * @param bool $withUxIcons Register symfony/ux-icons
     */
    public function __construct(private readonly array $searchKitConfig = [], private readonly bool $withUxIcons = true)
    {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
        // REQ-TWIG-004: Twig Extra registered like in a Flex application.
        yield new TwigExtraBundle();
        if ($this->withUxIcons) {
            yield new UXIconsBundle();
        }
        yield new NowoSearchKitBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-search-kit-bundle/' . md5(serialize([$this->searchKitConfig, $this->withUxIcons])) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-search-kit-bundle/logs';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'                => 'search-kit-test',
            'test'                  => true,
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'assets'                => ['enabled' => true],
            'form'                  => ['enabled' => true],
            'translator'            => ['enabled' => true, 'fallbacks' => ['en']],
            'default_locale'        => 'en',
            'session'               => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            // Filesystem, not array: the services resetter empties array pools between browser requests.
            'cache'  => ['app' => 'cache.adapter.filesystem'],
            'router' => ['utf8' => true],
        ]);
        $container->extension('security', [
            'providers' => ['test_users' => ['memory' => ['users' => [
                'alice' => ['password' => 'x', 'roles' => ['ROLE_USER']],
                'bob'   => ['password' => 'x', 'roles' => ['ROLE_USER']],
                'admin' => ['password' => 'x', 'roles' => ['ROLE_ADMIN']],
            ]]]],
            'firewalls' => ['main' => ['lazy' => true, 'provider' => 'test_users']],
        ]);
        $container->extension('twig', ['strict_variables' => true, 'auto_reload' => true, 'paths' => [__DIR__ . '/templates' => 'SearchKitTest']]);
        if ($this->withUxIcons) {
            // Only `search` and `bug` exist: every other icon falls back to the built-in SVG.
            $container->extension('ux_icons', ['icon_dir' => __DIR__ . '/icons', 'iconify' => ['enabled' => false]]);
        }
        $container->extension('nowo_search_kit', array_replace_recursive([
            'quick_actions' => [
                ['label' => 'Configured page', 'route' => 'test_page', 'route_parameters' => ['tab' => 'x']],
                ['label' => 'Docs', 'url' => 'https://example.com/docs', 'kind' => 'page'],
                ['label' => 'Admin only', 'url' => '/admin', 'role' => 'ROLE_ADMIN'],
            ],
            'menu_pages' => ['enabled' => true, 'service' => 'app.fake_menu', 'menus' => [['code' => 'main', 'label' => 'Main'], 'admin', 'missing']],
        ], $this->searchKitConfig));

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(ProjectsProvider::class);
        $services->set(FailingProvider::class);
        $services->set(TestScopeResolver::class);
        $services->alias(SearchScopeResolverInterface::class, TestScopeResolver::class);
        $services->set('app.fake_menu', FakeMenu::class)->factory([FakeMenu::class, 'demo']);
        $services->alias(self::ASSET_PACKAGES_ALIAS, 'assets.packages')->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@NowoSearchKitBundle/Resources/config/routes.php')->prefix('/search', false);
        $routes->add('test_page', '/page')->controller([self::class, 'page']);
        $routes->add('test_project_page', '/projects/{id}/page')->controller([self::class, 'page']);
        $routes->add('test_attrs_page', '/page-attrs')->controller([self::class, 'attrsPage']);
    }

    /** A signed-in page of the host application: renders the header search. */
    public function page(Environment $twig): Response
    {
        return new Response($twig->render('@SearchKitTest/page.html.twig'));
    }

    /** A host page that adds attributes to the root element (`nowo_search_root_attr`). */
    public function attrsPage(Environment $twig): Response
    {
        return new Response($twig->render('@SearchKitTest/page_attrs.html.twig'));
    }

    protected function build(ContainerBuilder $container): void
    {
        // Expose the bundle services to the tests.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->getDefinitions() as $id => $definition) {
                    if (str_starts_with($id, 'Nowo\\SearchKitBundle\\') || str_starts_with($id, 'limiter.nowo_search')) {
                        $definition->setPublic(true);
                    }
                }
            }
        });
    }
}
