<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\Everywhere;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\SearchKitBundle\Contract\FilterableSearchProviderInterface;
use Nowo\SearchKitBundle\Contract\IdentifierAwareSearchProviderInterface;
use Nowo\SearchKitBundle\Contract\PaginatedSearchProviderInterface;
use Nowo\SearchKitBundle\Contract\SearchActionProviderInterface;
use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Model\SearchAction;
use Nowo\SearchKitBundle\Model\SearchContext;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Search\Action\ActionCollector;
use Nowo\SearchKitBundle\Search\Filter\SearchFilterParser;
use Nowo\SearchKitBundle\Search\Ranking\SearchRanker;
use Nowo\SearchKitBundle\Search\SearchEngine;
use Nowo\SearchKitBundle\Search\SearchRequest;
use Nowo\SearchKitBundle\Search\Synonym\ConceptGroupSynonymProvider;
use Nowo\SearchKitBundle\Search\Synonym\SynonymExpander;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The 1.8.0 engine pipeline: `>` prefix, filters (deny by default), totals, merge, `type:`, ranking, row access filter,
 * paging and failure isolation.
 */
final class EngineTest extends TestCase
{
    public function testTheActionsPrefixAndTheActionsGroupOnTop(): void
    {
        $engine = $this->engine([$this->provider('rows', [new SearchItem('Issue', '/issues/1')])]);
        $user   = new InMemoryUser('alice', null, ['ROLE_USER']);

        $only = $engine->execute(new SearchRequest('>', $user));
        self::assertTrue($only->actionsOnly);
        self::assertSame(['actions'], $this->keys($only->groups));
        self::assertSame('', $only->query?->text);
        self::assertSame(['actions', 'rows'], $this->keys($engine->search($user, 'issue')), 'matching actions come first');
        self::assertSame(['rows'], $this->keys($engine->search($user, 'zzz issue')), 'no matching action: no group');
        self::assertSame(['rows'], $this->keys($this->engine([$this->provider('rows', [new SearchItem('Issue', '/issues/1')])], ['actions_in_results' => false])->search($user, 'issue')));

        $failing = new ActionCollector([new class implements SearchActionProviderInterface {
            public function actions(SearchContext $context, ?string $scope): iterable
            {
                return [new SearchAction('Boom', '/page')];
            }
        }], Fixtures::access(), [], Fixtures::router(), Fixtures::translator(), null, 200);
        $logger = Fixtures::logger();
        $broken = new SearchEngine([], 2, 5, $logger, [], null, null, $failing, null, null, ['action_prefix' => '>']);
        // A translator failure in the group heading must not break the search.
        $brokenCollector = new ActionCollector([new class implements SearchActionProviderInterface {
            public function actions(SearchContext $context, ?string $scope): iterable
            {
                return [new SearchAction('Boom', '/page')];
            }
        }], Fixtures::access(), [], Fixtures::router(), new class implements TranslatorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                throw new RuntimeException('translator down');
            }

            public function getLocale(): string
            {
                return 'en';
            }
        });
        self::assertSame(['actions'], $this->keys($broken->searchFor('alice', '>')));
        $quiet = new SearchEngine([], 2, 5, $logger, [], null, null, $brokenCollector, null, null, ['action_prefix' => '>']);
        self::assertSame([], $quiet->searchFor('alice', '>boom'));
        self::assertSame(['error: Search actions failed: RuntimeException.'], $logger->lines);
        self::assertSame([], (new SearchEngine([], 2, 5, null, [], null, null, $failing, null, null, ['action_prefix' => '']))->searchFor('alice', '>boom'), 'prefix off: an ordinary query');
    }

    public function testFiltersRunOnlyTheProvidersThatSupportThem(): void
    {
        $plain      = $this->provider('plain', [new SearchItem('Open bug', '/p')]);
        $filterable = new class implements FilterableSearchProviderInterface, IdentifierAwareSearchProviderInterface {
            public ?SearchQuery $query = null;

            public function supportedFilters(): array
            {
                return ['is'];
            }

            public function search(SearchQuery $query): SearchGroup
            {
                $this->query = $query;

                return new SearchGroup('issues', 'Issues', [new SearchItem('Open bug', '/i')]);
            }
        };
        $engine = new SearchEngine([$plain, $filterable], 2, 5, null, [], new SearchFilterParser(['is', 'assignee', 'type']));

        self::assertSame(['issues'], $this->keys($engine->searchFor('alice', 'bug is:open')));
        self::assertSame('bug', $filterable->query?->text);
        self::assertSame([], $engine->searchFor('alice', 'bug assignee:me'), 'nobody supports assignee: deny by default');
        self::assertSame(['issues'], $this->keys($engine->searchFor('alice', 'is:open')), 'filters only: the filterable providers');
        self::assertSame([], $engine->searchFor('alice', 'b is:open'), 'a short text with filters is refused');
        self::assertSame(['plain', 'issues'], $this->keys($engine->searchFor('alice', 'bug type:plain type:issues')), 'type: runs every provider');
        self::assertSame(['issues'], $this->keys($engine->searchFor('alice', 'bug -type:plain')));
        self::assertSame([], $engine->searchFor('alice', 'bug type:secret'));
        self::assertSame([], $engine->searchFor('alice', 'type:plain'), 'type only: the 1.x provider never gets an empty text');
        $result = $engine->execute(new SearchRequest('bug is:open', null, 'alice'));
        self::assertSame(['is:open'], array_map(static fn ($f): string => $f->toToken(), $result->filters));
    }

    /**
     * 1.10.0 (audit VK-1): a provider whose group key the `type:` filters exclude is never called, in the palette or
     * on the results page.
     */
    public function testTypeFilteredOutProvidersAreNeverCalled(): void
    {
        $tasks = new class implements PaginatedSearchProviderInterface {
            public int $calls = 0;

            public function groupKey(): string
            {
                return 'tasks';
            }

            public function search(SearchQuery $query): SearchGroup
            {
                ++$this->calls;

                return new SearchGroup('tasks', 'Tasks', [new SearchItem('Bug task', '/tasks/1')]);
            }

            public function searchPage(SearchQuery $query, int $page, int $perPage): SearchGroup
            {
                return $this->search($query);
            }
        };
        $engine = new SearchEngine([$tasks], 2, 5, null, [], new SearchFilterParser(['type']));
        $user   = new InMemoryUser('alice', null, ['ROLE_USER']);

        self::assertSame([], $engine->execute(new SearchRequest('bug type:other', $user))->groups);
        self::assertSame([], $engine->execute(new SearchRequest('bug -type:tasks', $user))->groups);
        self::assertNull($engine->page(new SearchRequest('bug type:other', $user), 'tasks', 1, 10));
        self::assertSame(0, $tasks->calls, 'A provider the type: filters exclude must never run.');

        self::assertSame(['tasks'], $this->keys($engine->execute(new SearchRequest('bug type:tasks', $user))->groups));
        self::assertInstanceOf(SearchGroup::class, $engine->page(new SearchRequest('bug', $user), 'tasks', 1, 10));
        self::assertSame(2, $tasks->calls);
    }

    public function testTotalsMergeRankingAndTheRowAccessFilter(): void
    {
        $now     = new DateTimeImmutable();
        $menu    = $this->provider('pages', [new SearchItem('A', '/page'), new SearchItem('B', '/settings/b')], 2, true);
        $pages   = $this->provider('pages', [new SearchItem('B again', '/settings/b'), new SearchItem('C', '/settings/c', '', null, null, null, 0.9)], 7, true);
        $claims  = $this->provider('claims', [new SearchItem('Old', '/issues/1', '', null, null, null, 0.5, $now->modify('-400 days')), new SearchItem('New', '/issues/2', '', null, null, null, 0.5, $now)], 40);
        $guessed = $this->provider('guessed', [new SearchItem('x', '/issues/3')], 9, false);
        $engine  = new SearchEngine([$menu, $pages, $claims, $guessed], 2, 5, null, [], null, null, null, Fixtures::access(['/issues/2']), new SearchRanker(), ['filter_all_rows' => false]);

        $groups = $engine->searchFor('alice', 'query');
        self::assertSame(['pages', 'claims', 'guessed'], $this->keys($groups));
        self::assertSame(['C', 'A', 'B'], $this->titles($groups[0]), 'merged without the duplicate URL, then ranked');
        self::assertSame(8, $groups[0]->total, 'exact + exact - 1 duplicate');
        self::assertSame(['New', 'Old'], $this->titles($groups[1]), 'recency breaks the tie');
        self::assertSame(2, $groups[1]->total, 'a total that is not exact is capped to the rows');
        self::assertSame(1, $groups[2]->total);

        $filtered = new SearchEngine([$menu, $pages, $claims, $guessed], 2, 5, null, [], null, null, null, Fixtures::access(['/issues/2', '/settings/b']), null, ['filter_all_rows' => true]);
        $groups   = $filtered->searchFor('alice', 'query');
        self::assertSame(['A', 'C'], $this->titles($groups[0]));
        self::assertSame(2, $groups[0]->total, 'a row removed by the access filter makes the total inexact');
        self::assertFalse($groups[0]->exactTotal);
        self::assertSame(['Old'], $this->titles($groups[1]));

        $mixed = new SearchEngine([$menu, $guessed, $this->provider('guessed', [new SearchItem('y', '/issues/4')], 1, true)], 2, 5);
        self::assertSame(2, $mixed->searchFor('alice', 'query')[1]->total, 'inexact merge: the number of rows');

        $denyAll = new SearchEngine([$menu], 2, 5, null, [], null, null, null, null, null, ['filter_all_rows' => true]);
        self::assertSame([], $denyAll->searchFor('alice', 'query'), 'no access service: every row is denied');
    }

    public function testSynonymsAndTheLocaleReachTheProviders(): void
    {
        $spy = new class implements IdentifierAwareSearchProviderInterface {
            public ?SearchQuery $query = null;

            public function search(SearchQuery $query): ?SearchGroup
            {
                $this->query = $query;

                return null;
            }
        };
        $engine = new SearchEngine([$spy], 2, 5, null, [], null, new SynonymExpander([new ConceptGroupSynonymProvider()], ['en', 'es']), null, null, null, ['fuzzy' => true]);
        $engine->searchFor('alice', 'contraseña', 'acme', 'es');
        self::assertSame('es', $spy->query?->locale);
        self::assertTrue($spy->query->fuzzy);
        self::assertSame('acme', $spy->query->scope);
        self::assertSame(['contraseña', 'clave', 'password', 'passphrase'], array_map(static fn ($t): string => $t->text, $spy->query->terms()));
    }

    public function testPagingFindsThePaginatedProviderOfAGroup(): void
    {
        $paged = new class implements PaginatedSearchProviderInterface, IdentifierAwareSearchProviderInterface {
            public function groupKey(): string
            {
                return 'issues';
            }

            public function search(SearchQuery $query): ?SearchGroup
            {
                return null;
            }

            public function searchPage(SearchQuery $query, int $page, int $perPage): ?SearchGroup
            {
                if ($page === 9) {
                    throw new RuntimeException('down');
                }

                return $page > 2 ? null : new SearchGroup('issues', 'Issues', [new SearchItem('Row ' . $page, '/i/' . $page), new SearchItem('Extra', '/x/' . $page)]);
            }
        };
        $engine  = new SearchEngine([$this->provider('other', []), $paged], 2, 5, Fixtures::logger(), [], null, null, $this->collector());
        $request = new SearchRequest('row', null, 'alice', null, null, null, 99);
        self::assertTrue($engine->isPaginated('issues'));
        self::assertFalse($engine->isPaginated('other'));
        self::assertSame(['Row 2'], $this->titles($engine->page($request, 'issues', 2, 1)), 'cut to per_page');
        self::assertSame(['Row 1'], $this->titles($engine->page($request, 'issues', 0, 1)), 'page 0 is page 1');
        self::assertNull($engine->page($request, 'issues', 3, 1));
        self::assertNull($engine->page($request, 'issues', 9, 1));
        self::assertNull($engine->page($request, 'other', 1, 1));
        self::assertNull($engine->page(new SearchRequest('r', null, 'alice'), 'issues', 1, 1), 'too short');
        self::assertNull($engine->page(new SearchRequest('>row', null, 'alice'), 'issues', 1, 1), 'actions only');
    }

    public function testAFailingProviderDropsOnlyItsGroup(): void
    {
        $logger  = Fixtures::logger();
        $failing = new class implements SearchProviderInterface {
            public function search(SearchQuery $query): ?SearchGroup
            {
                throw new RuntimeException('secret detail');
            }
        };
        $engine = new SearchEngine([$failing, $this->provider('ok', [new SearchItem('x', '/x')])], 2, 5, $logger);
        self::assertSame(['ok'], $this->keys($engine->search(new InMemoryUser('alice', null), 'query')));
        self::assertStringNotContainsString('secret detail', implode("\n", $logger->lines));
        self::assertStringNotContainsString('query', implode("\n", $logger->lines), 'the query text is never logged');
    }

    public function testARequestNeedsAViewer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SearchRequest('x');
    }

    /**
     * @param list<SearchProviderInterface> $providers
     * @param array<string, mixed> $options
     */
    private function engine(array $providers, array $options = []): SearchEngine
    {
        return new SearchEngine($providers, 2, 5, null, [], new SearchFilterParser(['is']), null, $this->collector(), Fixtures::access(), new SearchRanker(), $options + ['action_prefix' => '>']);
    }

    private function collector(): ActionCollector
    {
        return new ActionCollector([new class implements SearchActionProviderInterface {
            public function actions(SearchContext $context, ?string $scope): iterable
            {
                yield new SearchAction('Create issue', '/page', ['new']);
            }
        }], Fixtures::access(), [], Fixtures::router(), Fixtures::translator());
    }

    /**
     * @param list<SearchItem> $items
     */
    private function provider(string $key, array $items, ?int $total = null, bool $exact = false): SearchProviderInterface
    {
        return new class($key, $items, $total, $exact) implements IdentifierAwareSearchProviderInterface {
            /**
             * @param list<SearchItem> $items
             */
            public function __construct(private readonly string $key, private readonly array $items, private readonly ?int $total, private readonly bool $exact)
            {
            }

            public function search(SearchQuery $query): ?SearchGroup
            {
                return $this->items === [] ? null : new SearchGroup($this->key, ucfirst($this->key), $this->items, $this->total, null, null, $this->exact);
            }
        };
    }

    /**
     * @param list<SearchGroup> $groups
     *
     * @return list<string>
     */
    private function keys(array $groups): array
    {
        return array_map(static fn (SearchGroup $group): string => $group->key, $groups);
    }

    /**
     * @return list<string>
     */
    private function titles(?SearchGroup $group): array
    {
        return array_map(static fn (SearchItem $item): string => $item->title, $group->items ?? []);
    }
}
