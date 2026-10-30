<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Integration;

use Nowo\SearchKitBundle\Controller\SearchController;
use Nowo\SearchKitBundle\Tests\Support\HostControllersRoutesKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\RouterInterface;

use function is_string;

/**
 * REQ-SF-004 rule 7: a host whose routing is only `routing.controllers` (Symfony >= 7.3 default
 * `config/routes.yaml`) must not get the bundle route without importing the bundle `routes.php`.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class HostControllersRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('routing.controllers requires symfony/routing >= 7.3.');
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . HostControllersRoutesKernel::CACHE_ROOT);
    }

    public function testRoutingControllersImportDoesNotRegisterBundleRoutes(): void
    {
        $kernel = new HostControllersRoutesKernel();
        $kernel->boot();

        try {
            $container = $kernel->getContainer();
            self::assertTrue($container->has(SearchController::class), 'The search controller service must be registered.');

            $router = $container->get('router');
            self::assertInstanceOf(RouterInterface::class, $router);
            $routes = $router->getRouteCollection();

            self::assertNull($routes->get(SearchController::ROUTE), 'The bundle route must stay opt-in.');
            foreach ($routes->all() as $name => $route) {
                $controller = $route->getDefault('_controller');
                self::assertFalse(
                    is_string($controller) && str_starts_with($controller, SearchController::class),
                    'Route "' . $name . '" points to the bundle controller.',
                );
            }
        } finally {
            $kernel->shutdown();
        }
    }
}
