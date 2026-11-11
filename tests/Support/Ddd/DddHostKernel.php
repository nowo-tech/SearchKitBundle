<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Ddd;

use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\SearchKitBundle\Contract\SearchContextScopeResolverInterface;
use Nowo\SearchKitBundle\NowoSearchKitBundle;
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
use Twig\Environment;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;

/**
 * A DDD / hexagonal host (REQ-ARCH-001): the domain is a plain-PHP read model keyed by user identifier, and the host
 * implements only the 1.7 ports (providers read SearchQuery::$userIdentifier, the scope resolver gets a
 * SearchContext). No Doctrine (the bundle has no entities). The services resetter is removed, as in FrankenPHP worker
 * mode with a host that never resets (REQ-RUNTIME-002).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class DddHostKernel extends Kernel
{
    use MicroKernelTrait;

    public const string PROJECT_ROUTE = 'ddd_project_page';

    public function __construct()
    {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
        yield new TwigExtraBundle();
        yield new NowoFormKitBundle();
        yield new NowoSearchKitBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-search-kit-bundle/ddd/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-search-kit-bundle/logs';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'                => 'search-kit-ddd-test',
            'test'                  => true,
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'assets'                => ['enabled' => true],
            'form'                  => ['enabled' => true],
            'translator'            => ['enabled' => true, 'fallbacks' => ['en']],
            'default_locale'        => 'en',
            'session'               => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'cache'                 => ['app' => 'cache.adapter.filesystem'],
            'router'                => ['utf8' => true],
        ]);
        $container->extension('security', [
            'providers' => ['users' => ['memory' => ['users' => [
                'alice' => ['password' => 'x', 'roles' => ['ROLE_USER']],
                'bob'   => ['password' => 'x', 'roles' => ['ROLE_USER']],
            ]]]],
            'firewalls' => ['main' => ['lazy' => true, 'provider' => 'users']],
        ]);
        $container->extension('twig', ['strict_variables' => true, 'paths' => [dirname(__DIR__) . '/templates' => 'SearchKitTest']]);
        $container->extension('nowo_search_kit', ['min_length' => 1, 'rate_limit' => ['enabled' => false]]);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(ProjectDirectory::class);
        $services->set(ProjectSearchProvider::class);
        $services->set(ProjectScopeResolver::class);
        $services->alias(SearchContextScopeResolverInterface::class, ProjectScopeResolver::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@NowoSearchKitBundle/Resources/config/routes.php')->prefix('/search', false);
        $routes->add(self::PROJECT_ROUTE, '/projects/{id}/page')->controller([self::class, 'page']);
    }

    /** A host page rendering the header search. */
    public function page(Environment $twig): Response
    {
        return new Response($twig->render('@SearchKitTest/page.html.twig'));
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                // Worker mode with a host that never resets: no services resetter between requests.
                $container->removeDefinition('services_resetter');
            }
        });
    }
}
