<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Integration;

use Nowo\SearchKitBundle\Controller\SearchController;
use Nowo\SearchKitBundle\Search\SearchEngine;
use Nowo\SearchKitBundle\Tests\Support\TestKernel;
use Nowo\SearchKitBundle\Twig\SearchKitExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Smoke test: the bundle boots in a real kernel; services, parameters, asset package and Twig helpers outside a request.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class BundleBootTest extends TestCase
{
    protected function tearDown(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-search-kit-bundle');
    }

    public function testKernelBootsAndWiresTheBundle(): void
    {
        $kernel = new TestKernel();
        $kernel->boot();

        try {
            $container = $kernel->getContainer();
            self::assertSame('IS_AUTHENTICATED', $container->getParameter('nowo_search_kit.role'));
            self::assertSame(5, $container->getParameter('nowo_search_kit.per_group'));
            self::assertInstanceOf(SearchEngine::class, $container->get(SearchEngine::class));
            self::assertInstanceOf(SearchController::class, $container->get(SearchController::class));
            self::assertTrue($container->has('limiter.nowo_search_kit'));

            $packages = $container->get(TestKernel::ASSET_PACKAGES_ALIAS);
            self::assertInstanceOf(Packages::class, $packages);
            self::assertSame('/bundles/nowosearchkit/js/nowo-search.js', $packages->getUrl('js/nowo-search.js', 'nowo_search_kit'));

            // Outside a request (console, mail rendering): no user, no scope, no search box.
            $twig = $container->get(SearchKitExtension::class);
            self::assertInstanceOf(SearchKitExtension::class, $twig);
            self::assertNull($twig->scope());
            self::assertNull($twig->config());
            self::assertSame(['nowo_search_kit_config', 'nowo_search_kit_scope', 'nowo_search_kit_form', 'nowo_search_kit_icons'], array_map(static fn ($f): string => $f->getName(), $twig->getFunctions()));
        } finally {
            $kernel->shutdown();
        }
    }
}
