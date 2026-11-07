<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support;

use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\SearchKitBundle\NowoSearchKitBundle;
use Nowo\SearchKitBundle\Tests\Support\Provider\ExemptIssueNumberProvider;
use Nowo\SearchKitBundle\Tests\Support\Provider\PlainLikeProvider;
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
 * Kernel with one provider that opted in to queries below `min_length` and one that did not, both autoconfigured as
 * a host would register them. Separate from TestKernel so that the other tests keep their groups.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class MinLengthExemptKernel extends Kernel
{
    use MicroKernelTrait;

    public const string CACHE_ROOT = '/nowo-search-kit-bundle-min-length-exempt';

    /**
     * @param array<string, mixed> $searchKitConfig The `nowo_search_kit` configuration
     */
    public function __construct(private readonly array $searchKitConfig)
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
        return sys_get_temp_dir() . self::CACHE_ROOT . '/' . md5(serialize($this->searchKitConfig)) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . self::CACHE_ROOT . '/logs';
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
            'session'               => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'cache'                 => ['app' => 'cache.adapter.filesystem'],
            'router'                => ['utf8' => true],
        ]);
        $container->extension('security', [
            'providers' => ['test_users' => ['memory' => ['users' => [
                'alice' => ['password' => 'x', 'roles' => ['ROLE_USER']],
            ]]]],
            'firewalls' => ['main' => ['lazy' => true, 'provider' => 'test_users']],
        ]);
        $container->extension('nowo_search_kit', $this->searchKitConfig);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(ExemptIssueNumberProvider::class);
        $services->set(PlainLikeProvider::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@NowoSearchKitBundle/Resources/config/routes.php')->prefix('/search', false);
    }
}
