<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\Everywhere;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\SearchKitBundle\Contract\SearchKeywordsAwareInterface;
use Nowo\SearchKitBundle\Model\SearchAccessRequest;
use Nowo\SearchKitBundle\Model\SearchAction;
use Nowo\SearchKitBundle\Model\SearchFilterValue;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchPage;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\MenuPagesProvider;
use Nowo\SearchKitBundle\Tests\Support\Provider\FakeMenu;
use Nowo\SearchKitBundle\Tests\Support\Provider\FakeMenuItem;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The 1.8.0 value objects and the Pages group's keywords, access check, ranking and recents resolution.
 */
final class ModelsAndMenuTest extends TestCase
{
    public function testActionsArePlainGetLinks(): void
    {
        $action = new SearchAction('New', '/new', [' new ', '', ...array_fill(0, 40, 'k')], 'tabler:plus', 'n', 'Acme');
        self::assertSame('new', $action->keywords[0]);
        self::assertCount(SearchAction::MAX_KEYWORDS, $action->keywords);
        $item = $action->toItem(0.5);
        self::assertSame(['New', '/new', 'Acme', 'action', 0.5, 'n'], [$item->title, $item->url, $item->subtitle, $item->kind, $item->score, $item->shortcut]);
        self::assertSame('n', $item->toArray('actions')['shortcut'] ?? null);
        self::assertArrayNotHasKey('shortcut', (new SearchItem('x', '/x'))->toArray('g'));
        self::assertArrayNotHasKey('score', $item->toArray('actions'), 'the score never leaves the server');

        foreach ([['  ', '/x'], ['Evil', 'javascript:alert(1)']] as [$label, $url]) {
            try {
                new SearchAction($label, $url);
                self::fail('expected an exception');
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testPagesValidateTheirShape(): void
    {
        $page = SearchPage::fromConfig(['id' => 'a.b', 'label' => 'L', 'translation_domain' => null, 'route' => 'r', 'route_parameters' => ['x' => 1], 'trail' => ['T'], 'icon' => 'i', 'keywords' => 'k', 'scope' => 'required', 'attribute' => 'A', 'subject' => 'S', 'scope_parameter' => 'id']);
        self::assertSame(['a.b', 'required', 'id', 'A', 'S'], [$page->id, $page->scope, $page->scopeParameter, $page->attribute, $page->subject]);
        foreach ([['bad id', 'L', 'r', 'none'], ['ok', ' ', 'r', 'none'], ['ok', 'L', ' ', 'none'], ['ok', 'L', 'r', 'sometimes']] as [$id, $label, $route, $scope]) {
            try {
                new SearchPage($id, $label, $route, [], null, [], null, null, $scope);
                self::fail('expected an exception');
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testSmallValueObjects(): void
    {
        self::assertSame(['value' => 'bob', 'label' => 'bob'], (new SearchFilterValue('bob'))->toArray());
        self::assertSame(['value' => 'bob', 'label' => 'Bob B.'], (new SearchFilterValue('bob', 'Bob B.'))->toArray());
        $request = new SearchAccessRequest('u', '/x', null);
        self::assertSame([SearchAccessRequest::KIND_PAGE, [], null, null, null], [$request->kind, $request->routeParameters, $request->attribute, $request->subject, $request->scope]);
        $date = new DateTimeImmutable('2026-01-01');
        $item = new SearchItem('t', '/t', 's', null, null, 'k', 0.3, $date, 'x');
        $copy = $item->withScore(0.9);
        self::assertSame([0.9, $date, 'x', 'k'], [$copy->score, $copy->date, $copy->shortcut, $copy->kind]);
        $group = (new SearchGroup('g', 'G', [$item], 5, '/more', 'More', true))->withItems([$copy, $copy]);
        self::assertSame([null, true, '/more'], [$group->total, $group->exactTotal, $group->moreUrl]);
        self::assertTrue($group->limit(1)->exactTotal);
        self::assertSame(2, $group->limit(1)->total);
        self::assertFalse($group->withItems([], 3, false)->exactTotal);
    }

    public function testMenuKeywordsAccessRankingAndRecents(): void
    {
        $withKeywords = new class('Security', '/settings/security') extends FakeMenuItemWithRoute implements SearchKeywordsAwareInterface {
            public function getSearchKeywords(): string
            {
                return 'password, 2fa';
            }
        };
        $menu = new FakeMenu(['main' => [
            ['item' => $withKeywords],
            ['item' => new FakeMenuItemWithRoute('Billing', '/settings/billing', 'billing')],
            ['item' => new FakeMenuItemWithRoute('Language', '/settings/language', 'language')],
            ['item' => new FakeMenuItem('Plain', '/plain')],
        ]]);
        $translator = Fixtures::translator(['en' => ['kw.billing' => 'invoice, payment']]);
        $provider   = new MenuPagesProvider($menu, $translator, [['code' => 'main', 'label' => null, 'translation_domain' => null]], 'tabler:file', true, ['billing' => 'kw.billing', 'language' => 'kw.missing'], 'messages', null, true);

        self::assertSame(['Security'], $this->titles($provider->search($this->query('2fa'))));
        self::assertSame(['Billing'], $this->titles($provider->search($this->query('invoice'))));
        self::assertNull($provider->search($this->query('kw.missing'))?->items[0] ?? null, 'a keywords key without message never matches itself');
        self::assertSame([], $this->titles($provider->search($this->query('settings'))), 'labels and keywords only, never the URL');
        $security = $provider->search($this->query('security'));
        self::assertEqualsWithDelta(1.0, (float) $security?->items[0]->score, 0.0001);
        self::assertTrue($security?->exactTotal);

        $plain = new MenuPagesProvider($menu, $translator, [['code' => 'main', 'label' => null, 'translation_domain' => null]], 'tabler:file', true, ['billing' => 'invoice, payment'], null);
        self::assertSame(['Billing'], $this->titles($plain->search($this->query('payment'))), 'a plain keywords map');
        self::assertNull($plain->search($this->query('payment'))?->items[0]->score, 'not ranked: provider order, as before');

        $checked = new MenuPagesProvider($menu, $translator, [['code' => 'main', 'label' => null, 'translation_domain' => null]], 'tabler:file', true, [], 'messages', Fixtures::access(['/settings/billing']), true);
        self::assertSame([], $this->titles($checked->search($this->query('billing'))), 'check_access: the checkers decide even for the menu tree');
        self::assertSame(['Security'], $this->titles($checked->search($this->query('secur'))));

        $resolved = $provider->resolve(new SearchReference('/settings/billing', 'pages', 'billing'), 'alice', null);
        self::assertSame(['Billing', '/settings/billing', null], [$resolved?->title, $resolved?->url, $resolved?->score]);
        self::assertNull($provider->resolve(new SearchReference('/gone', 'pages', 'x'), 'alice', null));
        self::assertNull((new MenuPagesProvider(new stdClass(), $translator))->resolve(new SearchReference('/x', 'pages', 'x'), 'alice', null));
    }

    private function query(string $text): SearchQuery
    {
        return new SearchQuery($text, null, 5, null, false, 'alice');
    }

    /**
     * @return list<string>
     */
    private function titles(?SearchGroup $group): array
    {
        return array_map(static fn (SearchItem $item): string => $item->title, $group->items ?? []);
    }
}

/**
 * Menu item with a route name (DashboardMenuBundle's getRouteName()).
 */
class FakeMenuItemWithRoute extends FakeMenuItemBase
{
    public function __construct(string $label, string $href, private readonly ?string $route = null)
    {
        parent::__construct($label, $href);
    }

    public function getRouteName(): ?string
    {
        return $this->route;
    }
}

/**
 * Non-final twin of the test FakeMenuItem (getLabel / getIcon / href).
 */
class FakeMenuItemBase
{
    public function __construct(private readonly string $label, public readonly string $href)
    {
    }

    public function getLabel(): string
    {
        return $this->label;
    }
}
