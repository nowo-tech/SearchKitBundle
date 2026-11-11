<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\Search;

use ArrayObject;
use InvalidArgumentException;
use Nowo\SearchKitBundle\Contract\MinLengthExemptSearchProviderInterface;
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

use function count;
use function ini_get;

use const JSON_THROW_ON_ERROR;

final class SearchEngineTest extends TestCase
{
    public function testAPatternBelowMinLengthReachesOnlyTheProvidersThatOptedIn(): void
    {
        $plain = $this->provider(static fn (SearchQuery $query): SearchGroup => new SearchGroup('plain', 'Plain', [new SearchItem($query->text, '/p')]));
        $optIn = new class implements MinLengthExemptSearchProviderInterface {
            public ?SearchQuery $query = null;

            public function search(SearchQuery $query): SearchGroup
            {
                $this->query = $query;

                return new SearchGroup('in', 'In', [new SearchItem($query->text, '/i')]);
            }
        };
        $user = new InMemoryUser('u', null);

        $engine = new SearchEngine([$plain, $optIn], 3, 5, null, ['^#\d+$']);
        self::assertSame(['in'], array_map(static fn (SearchGroup $g): string => $g->key, $engine->search($user, ' #7 ', 'p1')));
        self::assertSame('#7', $optIn->query?->text);
        self::assertSame('p1', $optIn->query->scope);
        self::assertTrue($optIn->query->belowMinLength);

        self::assertSame(['plain', 'in'], array_map(static fn (SearchGroup $g): string => $g->key, $engine->search($user, '#77')));
        self::assertFalse($optIn->query->belowMinLength, 'at min_length the query is an ordinary one');

        $optIn->query = null;
        self::assertSame([], $engine->search($user, 'ab'));
        self::assertSame([], $engine->search($user, '#' . "\xFF"), 'invalid UTF-8 never matches');
        self::assertNull($optIn->query);

        self::assertSame([], (new SearchEngine([$plain, $optIn], 3))->search($user, '#7'), 'off by default');
        self::assertNull($optIn->query);
    }

    public function testAnEmptyQueryIsNeverExempt(): void
    {
        $optIn = new class implements MinLengthExemptSearchProviderInterface {
            public int $calls = 0;

            public function search(SearchQuery $query): ?SearchGroup
            {
                ++$this->calls;

                return null;
            }
        };

        (new SearchEngine([$optIn], 3, 5, null, ['^\d*$']))->search(new InMemoryUser('u', null), '   ');
        self::assertSame(0, $optIn->calls);
    }

    public function testAnInvalidPatternIsRefusedNotIgnored(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid min_length exempt pattern');
        new SearchEngine([], 3, 5, null, ['a++']);
    }

    public function testAPatternTheEngineGivesUpOnRefusesTheQueryAndIsLogged(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, array<mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };
        $text  = str_repeat('a', 40) . 'b';
        $limit = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');

        try {
            $groups = (new SearchEngine([], 50, 5, $logger, ['^(?:a+)+$']))->search(new InMemoryUser('u', null), $text);
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
        }

        self::assertSame([], $groups);
        self::assertCount(1, $logger->records);
        self::assertSame('^(?:a+)+$', $logger->records[0][1]['pattern']);
        self::assertStringNotContainsString($text, json_encode($logger->records, JSON_THROW_ON_ERROR), 'the query is personal data');
    }

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

    public function testSearchingByIdentifierGivesTheProvidersTheSameGroupsWithoutASymfonyUser(): void
    {
        /** @var ArrayObject<int, SearchQuery> $seen */
        $seen    = new ArrayObject();
        $byOwner = $this->provider(static function (SearchQuery $query) use ($seen): ?SearchGroup {
            $seen[] = $query;
            $rows   = ['alice' => ['Acme', 'Beta'], 'bob' => ['Beta']][$query->userIdentifier] ?? [];
            $rows   = array_values(array_filter($rows, static fn (string $row): bool => $query->matches($row) && ($query->scope === null || strtolower($row) === $query->scope)));

            return $rows === [] ? null : new SearchGroup('p', 'P', array_map(static fn (string $row): SearchItem => new SearchItem($row, '/p/' . strtolower($row)), $rows));
        });
        $engine = new SearchEngine([$byOwner], 1, 5);
        $keys   = static fn (array $groups): array => array_map(static fn (SearchGroup $group): array => array_map(static fn (SearchItem $item): string => $item->title, $group->items), $groups);

        foreach (['alice', 'bob', 'carol'] as $user) {
            foreach ([null, 'acme', 'beta', 'nope'] as $scope) {
                foreach (['a', 'acme', 'zzz'] as $text) {
                    $old = $engine->search(new InMemoryUser($user, null), $text, $scope);
                    $new = $engine->searchFor($user, $text, $scope);
                    self::assertSame($keys($old), $keys($new), $user . ' / ' . ($scope ?? '-') . ' / ' . $text);
                }
            }
        }
        self::assertSame([['Beta']], $keys($engine->searchFor('bob', 'a')), 'bob never sees acme');

        $first = $seen[0];
        $last  = $seen[count($seen) - 1];
        self::assertInstanceOf(SearchQuery::class, $first);
        self::assertInstanceOf(SearchQuery::class, $last);
        self::assertSame('bob', $last->userIdentifier);
        self::assertNull($last->user, 'by identifier: no Symfony user');
        self::assertSame('alice', $first->userIdentifier);
        self::assertSame('alice', $first->user?->getUserIdentifier(), 'the endpoint path keeps the deprecated user');
    }

    public function testAProviderStillReadingTheDeprecatedUserFailsClosedWhenSearchingByIdentifier(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, array<mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };
        $legacy = $this->provider(static function (SearchQuery $query): SearchGroup {
            $user = $query->user ?? throw new RuntimeException('no Symfony user');

            return new SearchGroup('legacy', 'Legacy', [new SearchItem($user->getUserIdentifier(), '/l')]);
        });
        $engine = new SearchEngine([$legacy], 1, 5, $logger);

        self::assertSame([], $engine->searchFor('alice', 'shop'), 'skipped, never rows for someone else');
        self::assertCount(1, $logger->records);
        self::assertSame(RuntimeException::class, $logger->records[0][1]['exception']);
        self::assertCount(1, $engine->search(new InMemoryUser('alice', null), 'shop'), 'the endpoint path still feeds it');
    }

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
