<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\Icon;

use Nowo\SearchKitBundle\Icon\SearchIcons;
use PHPUnit\Framework\TestCase;
use Symfony\UX\Icons\Exception\IconNotFoundException;
use Symfony\UX\Icons\IconRendererInterface;

final class SearchIconsTest extends TestCase
{
    public function testUxIconsWithBuiltInFallback(): void
    {
        $renderer = new class implements IconRendererInterface {
            public function renderIcon(string $name, array $attributes = []): string
            {
                if ($name !== 'tabler:search') {
                    throw new IconNotFoundException($name);
                }

                return '<svg data-ux="' . $name . '" class="' . $attributes['class'] . '"></svg>';
            }
        };
        $icons = new SearchIcons('ux_icon', ['search' => 'tabler:search', 'close' => 'tabler:x', 'issues' => 'tabler:bug'], $renderer);

        self::assertSame('<svg data-ux="tabler:search" class="nowo-search__svg"></svg>', $icons->forKey('search'));
        self::assertStringContainsString('M18 6 6 18', $icons->forKey('close'), 'missing from the set: built-in close');
        self::assertStringContainsString('r="3"', $icons->forKey('issues'), 'unknown kind: the generic item dot');
        self::assertStringContainsString('M14 3v4', $icons->forKey('pages'));
        self::assertNull($icons->render('tabler:bug'));

        $all = $icons->all();
        self::assertSame(['search', 'close', 'loading', 'pages', 'recent', 'action', 'item', 'issues'], array_keys($all));
        self::assertSame($icons->forKey('search'), (string) $all['search']);
    }

    public function testSvgInlineAndMissingRendererNeverCallUxIcons(): void
    {
        self::assertNull((new SearchIcons('svg_inline', ['search' => 'tabler:search']))->render('tabler:search'));
        self::assertNull((new SearchIcons('ux_icon'))->render('tabler:search'));
        self::assertStringContainsString('<circle cx="10" cy="10" r="7"/>', (new SearchIcons())->forKey('search'));
    }
}
