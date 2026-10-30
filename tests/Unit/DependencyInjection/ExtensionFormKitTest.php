<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\DependencyInjection;

use Nowo\FormKitBundle\DependencyInjection\FormKitExtension;
use Nowo\SearchKitBundle\DependencyInjection\NowoSearchKitExtension;
use Nowo\SearchKitBundle\NowoSearchKitBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The bundle prepends its FormKit profile (REQ-TWIG-005 rule 11 / REQ-UI-003 "Form theme").
 */
final class ExtensionFormKitTest extends TestCase
{
    public function testPrependsTheFormKitProfile(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FormKitExtension());

        (new NowoSearchKitExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_form_kit');
        self::assertCount(1, $configs);
        $profile = $configs[0]['profiles'][NowoSearchKitBundle::FORM_KIT_PROFILE];
        self::assertSame(NowoSearchKitExtension::formKitProfile(), $profile);
        self::assertSame('search_kit', $profile['alias']);
        self::assertSame('NowoSearchKitBundle', $profile['translation_domain']);
        self::assertFalse($profile['auto_help']);
        self::assertFalse($profile['auto_placeholder']);
    }

    public function testHostProfileWins(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FormKitExtension());
        $container->prependExtensionConfig('nowo_form_kit', ['translation_domain' => 'messages']);
        $container->prependExtensionConfig('nowo_form_kit', ['profiles' => ['search_kit' => ['alias' => 'search_kit', 'translation_domain' => 'app']]]);

        (new NowoSearchKitExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_form_kit');
        self::assertCount(2, $configs);
        self::assertSame('app', $configs[0]['profiles']['search_kit']['translation_domain']);
    }

    public function testNoFormKitExtensionPrependsNothing(): void
    {
        $container = new ContainerBuilder();

        (new NowoSearchKitExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('nowo_form_kit'));
    }
}
