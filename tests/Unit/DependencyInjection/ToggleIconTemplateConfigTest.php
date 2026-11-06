<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\DependencyInjection;

use Nowo\SearchKitBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ToggleIconTemplateConfigTest extends TestCase
{
    public function testItIsOffByDefault(): void
    {
        self::assertNull((new Processor())->processConfiguration(new Configuration(), [[]])['toggle_icon_template']);
    }

    public function testItAcceptsNullAndATemplatePath(): void
    {
        foreach ([null, 'search/_toggle_icon.html.twig', '@App/search/_toggle_icon.html.twig'] as $value) {
            $config = (new Processor())->processConfiguration(new Configuration(), [['toggle_icon_template' => $value]]);
            self::assertSame($value, $config['toggle_icon_template']);
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidValues(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'number' => [12];
    }

    #[DataProvider('invalidValues')]
    public function testItRefusesAnythingElse(mixed $value): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('toggle_icon_template');
        (new Processor())->processConfiguration(new Configuration(), [['toggle_icon_template' => $value]]);
    }
}
