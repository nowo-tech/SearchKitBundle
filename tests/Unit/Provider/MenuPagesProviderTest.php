<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\Provider;

use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Provider\MenuPagesProvider;
use Nowo\SearchKitBundle\Tests\Support\Provider\FakeMenu;
use Nowo\SearchKitBundle\Tests\Support\Provider\FakeMenuItem;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Translation\IdentityTranslator;

final class MenuPagesProviderTest extends TestCase
{
    public function testWalksTheMenusWithBreadcrumbsIconsAndDeduplication(): void
    {
        $menus = new FakeMenu([
            'main' => [
                ['item' => new FakeMenuItem('Reports', '/reports', 'tabler:chart'), 'children' => [
                    ['item' => new FakeMenuItem('Report builder', '/reports/builder')],
                    ['item' => new FakeMenuItem('', '/reports/hidden'), 'children' => [
                        ['item' => new FakeMenuItem('Deep report', '/reports/deep')],
                    ]],
                ]],
                ['item' => new FakeMenuItem('Report link out', 'javascript:alert(1)')],
                ['item' => new stdClass()],
                'not a node',
                ['item' => new FakeMenuItem('Other', '/other'), 'children' => 'broken'],
            ],
            'second' => [
                ['item' => new FakeMenuItem('Reports again', '/reports')],
                ['item' => new FakeMenuItem('Report', '/report-root')],
            ],
            'broken' => 'not a tree',
        ]);
        $provider = new MenuPagesProvider($menus, new IdentityTranslator(), [
            ['code' => 'second', 'label' => null, 'translation_domain' => null],
            ['code' => 'main', 'label' => 'Main', 'translation_domain' => 'messages'],
            ['code' => 'broken', 'label' => null, 'translation_domain' => null],
        ], 'tabler:file');

        $group = $provider->search(new SearchQuery('REPORT', new InMemoryUser('u', null), 10));
        self::assertNotNull($group);
        self::assertSame('pages', $group->key);
        self::assertSame('search.group.pages', $group->label);
        self::assertSame(4, $group->total);
        $rows = array_map(static fn ($item): array => [$item->title, $item->url, $item->subtitle, $item->icon], $group->items);
        self::assertSame([
            ['Reports again', '/reports', '', 'tabler:file'],
            ['Report', '/report-root', '', 'tabler:file'],
            ['Report builder', '/reports/builder', 'Main › Reports', 'tabler:file'],
            ['Deep report', '/reports/deep', 'Main › Reports', 'tabler:file'],
        ], $rows);

        $cut = $provider->search(new SearchQuery('report', new InMemoryUser('u', null), 1));
        self::assertNotNull($cut);
        self::assertCount(1, $cut->items);
        self::assertSame(4, $cut->total);
    }

    public function testUsesTheItemIconAndSkipsWhenScopedOrWithoutAMenuApi(): void
    {
        $menus    = new FakeMenu(['main' => [['item' => new FakeMenuItem('Home', '/', 'tabler:home')]]]);
        $provider = new MenuPagesProvider($menus, new IdentityTranslator(), [['code' => 'main', 'label' => null, 'translation_domain' => null]]);
        $group    = $provider->search(new SearchQuery('home', new InMemoryUser('u', null), 5));
        self::assertNotNull($group);
        self::assertSame('tabler:home', $group->items[0]->icon);

        self::assertNull($provider->search(new SearchQuery('home', new InMemoryUser('u', null), 5, 'scope')));
        $scoped = new MenuPagesProvider($menus, new IdentityTranslator(), [['code' => 'main', 'label' => null, 'translation_domain' => null]], 'tabler:file', false);
        self::assertNotNull($scoped->search(new SearchQuery('home', new InMemoryUser('u', null), 5, 'scope')));

        self::assertNull((new MenuPagesProvider(new stdClass(), new IdentityTranslator(), []))->search(new SearchQuery('home', new InMemoryUser('u', null), 5)));
    }
}
