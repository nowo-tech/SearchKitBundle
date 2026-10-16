<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\Model;

use InvalidArgumentException;
use Nowo\SearchKitBundle\Model\SearchBadge;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class ModelTest extends TestCase
{
    #[DataProvider('urls')]
    public function testUrlSafety(string $url, bool $safe): void
    {
        self::assertSame($safe, SearchItem::isSafeUrl($url));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function urls(): iterable
    {
        yield 'path' => ['/projects/1', true];
        yield 'https' => ['https://example.com/x', true];
        yield 'http upper' => ['HTTP://example.com', true];
        yield 'protocol relative' => ['//evil.example/x', false];
        yield 'backslash' => ['/\\evil.example', false];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'data' => ['data:text/html,x', false];
        yield 'relative' => ['projects/1', false];
        yield 'empty host' => ['https:///x', false];
        yield 'tab smuggling' => ["/\t/evil.example", false];
        yield 'newline smuggling' => ["/\n/evil.example", false];
        yield 'carriage return' => ["/\r\\evil.example", false];
        yield 'space in absolute URL' => ['https://example.com/a b', false];
        yield 'DEL' => ["/a\x7F", false];
    }

    public function testItemRejectsUnsafeUrlsAndSerializes(): void
    {
        $item = new SearchItem('Title', '/x', 'Sub', 'tabler:bug', new SearchBadge('Error', 'err<or>'), 'issue');
        self::assertSame([
            'title'    => 'Title',
            'subtitle' => 'Sub',
            'url'      => '/x',
            'kind'     => 'issue',
            'icon'     => '<svg/>',
            'badge'    => ['label' => 'Error', 'tone' => 'error'],
        ], $item->toArray('issues', '<svg/>'));
        self::assertSame('issues', (new SearchItem('T', '/x'))->toArray('issues')['kind']);

        $this->expectException(InvalidArgumentException::class);
        new SearchItem('Bad', 'javascript:alert(1)');
    }

    public function testGroupLimitAndSerialization(): void
    {
        $items = [new SearchItem('A', '/a'), new SearchItem('B', '/b'), new SearchItem('C', '/c')];
        $group = new SearchGroup('My Group!', 'Label', $items, null, 'javascript:x', 'More');
        self::assertSame($group, $group->limit(3));

        $cut = $group->limit(2);
        self::assertCount(2, $cut->items);
        self::assertSame(3, $cut->total);
        $array = $cut->toArray(static fn (SearchItem $item): string => '<i>' . $item->title . '</i>');
        self::assertSame('mygroup', $array['key']);
        self::assertSame(3, $array['total']);
        self::assertNull($array['more'], 'an unsafe "see all" URL is dropped');
        self::assertSame('<i>A</i>', $array['items'][0]['icon']);
        self::assertSame('mygroup', $array['items'][0]['kind']);

        $more = (new SearchGroup('k', 'L', $items, 9, '/all'))->toArray();
        self::assertSame(['url' => '/all', 'label' => '/all'], $more['more']);
        self::assertNull($more['items'][0]['icon']);
        self::assertSame(9, $more['total']);
    }

    public function testQueryAndScope(): void
    {
        $query = new SearchQuery('ÁRBOL', new InMemoryUser('u', null), 5);
        self::assertFalse($query->isScoped());
        self::assertTrue($query->matches('nope', 'Un árbol grande'));
        self::assertFalse($query->matches('nope'));
        self::assertTrue((new SearchQuery('x', new InMemoryUser('u', null), 5, 'p1'))->isScoped());

        self::assertSame(['id' => 'a-1', 'label' => 'A'], (new SearchScope('a-1', 'A'))->toArray());
        $this->expectException(InvalidArgumentException::class);
        new SearchScope('../etc', 'x');
    }
}
