<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\Search;

use ArrayObject;
use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Scope\NullScopeResolver;
use Nowo\SearchKitBundle\Search\SearchEngine;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\InMemoryUser;

use const JSON_THROW_ON_ERROR;

final class SearchEngineTest extends TestCase
{
    public function testProvidersRunInOrderAndGroupsAreFilteredAndCut(): void
    {
        /** @var ArrayObject<int, SearchQuery> $seen */
        $seen   = new ArrayObject();
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, array<mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };
        $engine = new SearchEngine([
            $this->provider(static function (SearchQuery $query) use ($seen): SearchGroup {
                $seen[] = $query;

                return new SearchGroup('a', 'A', array_map(static fn (int $i): SearchItem => new SearchItem('Row ' . $i, '/r/' . $i), range(1, 4)));
            }),
            $this->provider(static fn (): ?SearchGroup => null),
            $this->provider(static fn (): SearchGroup => new SearchGroup('empty', 'Empty', [])),
            $this->provider(static fn (): SearchGroup => throw new RuntimeException('secret query text')),
            $this->provider(static fn (): SearchGroup => new SearchGroup('b', 'B', [new SearchItem('One', '/one')])),
        ], 3, 2, $logger);

        self::assertSame([], $engine->search(new InMemoryUser('u', null), '  ab  '));
        self::assertCount(0, $seen);

        $groups = $engine->search(new InMemoryUser('u', null), '  abc  ', 'p1');
        self::assertSame(['a', 'b'], array_map(static fn (SearchGroup $g): string => $g->key, $groups));
        self::assertCount(2, $groups[0]->items);
        self::assertSame(4, $groups[0]->total);
        $first = $seen[0];
        self::assertInstanceOf(SearchQuery::class, $first);
        self::assertSame('abc', $first->text);
        self::assertSame('p1', $first->scope);
        self::assertSame(2, $first->limit);
        self::assertCount(1, $logger->records);
        self::assertSame(RuntimeException::class, $logger->records[0][1]['exception']);
        self::assertStringNotContainsString('secret', json_encode($logger->records, JSON_THROW_ON_ERROR));

        // Without a logger a failing provider is still skipped.
        self::assertSame([], (new SearchEngine([$this->provider(static fn (): SearchGroup => throw new RuntimeException('x'))]))->search(new InMemoryUser('u', null), 'abc'));
    }

    public function testNullScopeResolver(): void
    {
        self::assertNull((new NullScopeResolver())->resolve(new Request(), new InMemoryUser('u', null)));
    }

    /**
     * @param callable(SearchQuery): ?SearchGroup $search
     */
    private function provider(callable $search): SearchProviderInterface
    {
        return new class($search) implements SearchProviderInterface {
            /** @var callable(SearchQuery): ?SearchGroup */
            private $search;

            public function __construct(callable $search)
            {
                $this->search = $search;
            }

            public function search(SearchQuery $query): ?SearchGroup
            {
                return ($this->search)($query);
            }
        };
    }
}
