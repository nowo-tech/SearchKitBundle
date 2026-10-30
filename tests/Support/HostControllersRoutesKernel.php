<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support;

use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\SearchKitBundle\NowoSearchKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;

/**
 * Kernel whose routing is only the Symfony default `controllers: { resource: routing.controllers }` (no import of
 * the bundle `routes.php`): the bundle route must not be registered there (REQ-SF-004 rule 7).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class HostControllersRoutesKernel extends Kernel
{
    use MicroKernelTrait;

    public const string CACHE_ROOT = '/nowo-search-kit-bundle-host-controllers';

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
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . self::CACHE_ROOT . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . self::CACHE_ROOT . '/logs';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'search-kit-test',
            'test'                 => true,
            'http_method_override' => false,
            'assets'               => ['enabled' => true],
            'form'                 => ['enabled' => true],
            'session'              => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'router'               => ['utf8' => true],
        ]);
        $container->extension('security', [
            'providers' => ['test_users' => ['memory' => ['users' => []]]],
            'firewalls' => ['main' => ['lazy' => true, 'provider' => 'test_users']],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('routing.controllers');
    }
}
