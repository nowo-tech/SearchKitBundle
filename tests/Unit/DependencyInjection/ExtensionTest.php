<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\DependencyInjection;

use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\DependencyInjection\Configuration;
use Nowo\SearchKitBundle\DependencyInjection\NowoSearchKitExtension;
use Nowo\SearchKitBundle\Provider\MenuPagesProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

final class ExtensionTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), []);
        self::assertSame('IS_AUTHENTICATED', $config['role']);
        self::assertSame(['enabled' => true, 'policy' => 'sliding_window', 'limit' => 30, 'interval' => '10 seconds'], $config['rate_limit']);
        self::assertNull($config['menu_pages']['enabled']);
        self::assertSame([], $config['quick_actions']);
    }

    public function testMenusAcceptPlainCodes(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['menu_pages' => ['menus' => ['main']]]]);
        self::assertSame([['code' => 'main', 'label' => null, 'translation_domain' => 'messages']], $config['menu_pages']['menus']);
    }

    public function testQuickActionNeedsExactlyOneTarget(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('exactly one of "route" or "url"');
        (new Processor())->processConfiguration(new Configuration(), [['quick_actions' => [['label' => 'x', 'route' => 'r', 'url' => '/x']]]]);
    }

    public function testQuickActionUrlMustBeSafe(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must be a path starting with "/" or an http(s) URL');
        (new Processor())->processConfiguration(new Configuration(), [['quick_actions' => [['label' => 'x', 'url' => 'javascript:alert(1)']]]]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidPopoverStylesheets(): iterable
    {
        yield 'true' => [true];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'protocol relative' => ['//cdn.example/x.css'];
        yield 'number' => [12];
    }

    #[DataProvider('invalidPopoverStylesheets')]
    public function testPopoverStylesheetIsValidated(mixed $value): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [['assets' => ['popover_stylesheet' => $value]]]);
    }

    public function testPopoverStylesheetAcceptsNullFalseAndUrls(): void
    {
        foreach ([null, false, '/build/x.css', 'https://cdn.example/x.css'] as $value) {
            $config = (new Processor())->processConfiguration(new Configuration(), [['assets' => ['popover_stylesheet' => $value]]]);
            self::assertSame($value, $config['assets']['popover_stylesheet']);
        }
    }

    public function testStorageKeyIsValidated(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [['recent' => ['storage_key' => 'bad key']]]);
    }

    public function testPrependWithoutFrameworkDoesNothing(): void
    {
        $container = new ContainerBuilder();
        (new NowoSearchKitExtension())->prepend($container);
        self::assertSame([], $container->getExtensionConfig('framework'));
    }

    public function testPrependRegistersAssetsAndLimiter(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'framework';
            }
        });
        $extension = new NowoSearchKitExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension('nowo_search_kit', ['rate_limit' => ['enabled' => false], 'role' => 'ROLE_USER']);
        $extension->prepend($container);

        $framework = $container->getExtensionConfig('framework');
        self::assertCount(1, $framework);
        self::assertSame('/bundles/nowosearchkit', $framework[0]['assets']['packages']['nowo_search_kit']['base_path']);
    }

    public function testPagesProviderAutoDetection(): void
    {
        $container = new ContainerBuilder();
        (new NowoSearchKitExtension())->load([['menu_pages' => ['menus' => ['main']]]], $container);
        self::assertFalse($container->hasDefinition(MenuPagesProvider::class), 'DashboardMenuBundle is not installed here');

        $container = new ContainerBuilder();
        (new NowoSearchKitExtension())->load([['menu_pages' => ['service' => self::class, 'menus' => ['main'], 'priority' => 7]]], $container);
        self::assertTrue($container->hasDefinition(MenuPagesProvider::class), 'auto: the menu service class exists');
        self::assertSame([['priority' => 7]], $container->getDefinition(MenuPagesProvider::class)->getTag(SearchProviderInterface::TAG));

        $container = new ContainerBuilder();
        (new NowoSearchKitExtension())->load([['menu_pages' => ['service' => self::class]]], $container);
        self::assertFalse($container->hasDefinition(MenuPagesProvider::class), 'auto: no menus, no group');
        self::assertSame('nowo_search_kit', (new NowoSearchKitExtension())->getAlias());
    }
}
