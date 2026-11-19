<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Test;

use Nowo\SearchKitBundle\Event\SearchItemVisibilityEvent;
use Nowo\SearchKitBundle\Event\SearchKitEvents;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function array_intersect;
use function array_map;
use function bin2hex;
use function count;
use function implode;
use function random_bytes;

/**
 * The SearchKit provider contract (1.9.0 test kit). Extend it in the bundle's provider test, with the test database
 * (real MySQL / MariaDB / PostgreSQL in CI) behind a connection that uses QueryCountingMiddleware, and implement the
 * hooks: the kit then asserts, against your real definition and query, that.
 *
 * - every refusal (no tenant, feature off, a malformed or unknown scope, a host deny) gives `null`;
 * - no row of another tenant is ever shown or counted;
 * - LIKE wildcards (`%`, `_`, `!`, `\`) in the query are matched literally;
 * - the limit holds, the total is the visible total, and the results page is bounded;
 * - one search stays within the query budget (no N+1);
 * - one provider instance serves two viewers alternately with no state kept (FrankenPHP worker mode).
 *
 * Each test seeds rows under a fresh random marker, so the tests need no cleanup between them and never see each
 * other's rows. The seeded text must end up in one of searchableFields() of a row the viewer may see.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
abstract class EntitySearchProviderTestCase extends TestCase
{
    /** Viewer of the first tenant. */
    protected const string VIEWER_A = 'searchkit-contract-viewer-a';

    /** Viewer of the second tenant. */
    protected const string VIEWER_B = 'searchkit-contract-viewer-b';

    /**
     * The definition under test, wired to the test database (a new one per call is fine).
     */
    abstract protected function definition(): EntitySearchDefinition;

    /**
     * The counter of the test connection's QueryCountingMiddleware.
     */
    abstract protected function queryCounter(): QueryCounter;

    /**
     * Two different tenants.
     *
     * @return array{0: int|string, 1: int|string}
     */
    abstract protected function tenants(): array;

    /**
     * Inserts one row of the tenant, visible to its viewers, whose searchable text contains `$text`.
     *
     * @param int|string $tenant The tenant
     * @param string $text Text to put in a searchable field (may contain `%`, `_`, `!` and `\`)
     */
    abstract protected function seed(int|string $tenant, string $text): void;

    /**
     * Makes the definition resolve this viewer and tenant from now on (null tenant: the viewer has none), with the
     * feature on.
     *
     * @param int|string|null $tenant The tenant, or null
     * @param string $userIdentifier The viewer
     */
    abstract protected function actAs(int|string|null $tenant, string $userIdentifier): void;

    /**
     * Turns the bundle's feature (or the viewer's `view` permission) off for the current viewer.
     */
    abstract protected function disableFeature(): void;

    /**
     * Most SQL statements of one search (default 2: the search query and one batched access check).
     */
    protected function queryBudget(): int
    {
        return 2;
    }

    /**
     * The provider under test (SearchKit's generic provider around definition()).
     *
     * @param EventDispatcher|null $dispatcher The dispatcher of the host event (null: a new empty one)
     */
    protected function provider(?EventDispatcher $dispatcher = null): EntitySearchProvider
    {
        return new EntitySearchProvider($this->definition(), $dispatcher ?? new EventDispatcher());
    }

    /**
     * A query of a viewer.
     *
     * @param string $text Query text
     * @param int $limit Rows per group
     * @param string|null $scope Scope id, or null
     * @param string $viewer The viewer
     */
    protected function query(string $text, int $limit = 10, ?string $scope = null, string $viewer = self::VIEWER_A): SearchQuery
    {
        return SearchQuery::forUserIdentifier($viewer, $text, $limit, $scope);
    }

    /**
     * A fresh marker (letters and digits only) that no other row contains.
     */
    protected function marker(): string
    {
        return 'skc' . bin2hex(random_bytes(6));
    }

    /**
     * A viewer without a tenant gets null.
     */
    public function testContractRefusesWithoutTenant(): void
    {
        [$tenant] = $this->tenants();
        $marker   = $this->marker();
        $this->seed($tenant, $marker);
        $this->actAs(null, self::VIEWER_A);

        self::assertNull($this->provider()->search($this->query($marker)));
    }

    /**
     * The feature gate off gives null (the row is found while it is on).
     */
    public function testContractRefusesWhenTheFeatureIsOff(): void
    {
        [$tenant] = $this->tenants();
        $marker   = $this->marker();
        $this->seed($tenant, $marker);
        $this->actAs($tenant, self::VIEWER_A);
        self::assertInstanceOf(SearchGroup::class, $this->provider()->search($this->query($marker)), 'The seeded row must be found before the feature is turned off.');

        $this->disableFeature();

        self::assertNull($this->provider()->search($this->query($marker)));
    }

    /**
     * A malformed scope and a scope the definition does not handle give null.
     */
    public function testContractRefusesMalformedAndUnknownScopes(): void
    {
        [$tenant] = $this->tenants();
        $marker   = $this->marker();
        $this->seed($tenant, $marker);
        $this->actAs($tenant, self::VIEWER_A);

        foreach (['../' . $marker, 'a b', 'searchkit-contract-unknown:' . $marker] as $scope) {
            self::assertNull($this->provider()->search($this->query($marker, 10, $scope)), 'Scope "' . $scope . '" must give null.');
        }
    }

    /**
     * One batched host event; a deny wins over a later allow, and a denied row is neither shown nor counted.
     */
    public function testContractHostDenyWinsAndGivesNull(): void
    {
        [$tenant] = $this->tenants();
        $marker   = $this->marker();
        $this->seed($tenant, $marker . 'one');
        $this->seed($tenant, $marker . 'two');
        $this->actAs($tenant, self::VIEWER_A);

        $seen       = 0;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SearchKitEvents::ITEM_VISIBILITY, static function (SearchItemVisibilityEvent $event) use (&$seen): void {
            $seen += count($event->subjects());
            $event->denyAll();
        });
        $dispatcher->addListener(SearchKitEvents::ITEM_VISIBILITY, static function (SearchItemVisibilityEvent $event): void {
            foreach ($event->subjects() as $subject) {
                $event->allow($subject);
            }
        });

        self::assertNull($this->provider($dispatcher)->search($this->query($marker)));
        self::assertSame(2, $seen, 'Both candidates must be given to the host event, in one batch.');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SearchKitEvents::ITEM_VISIBILITY, static function (SearchItemVisibilityEvent $event): void {
            $event->deny($event->subjects()[0]);
        });
        $group = $this->provider($dispatcher)->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertCount(1, $group->items);
        self::assertSame(1, $group->total, 'A denied row must not be counted.');
    }

    /**
     * Rows of another tenant are never shown or counted.
     */
    public function testContractNeverShowsAnotherTenantsRows(): void
    {
        [$first, $second] = $this->tenants();
        $marker           = $this->marker();
        $this->seed($first, $marker);
        $this->seed($second, $marker);
        $this->seed($second, $marker);

        $this->actAs($first, self::VIEWER_A);
        $group = $this->provider()->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertCount(1, $group->items);
        self::assertSame(1, $group->total);

        $this->actAs($second, self::VIEWER_B);
        $group = $this->provider()->search($this->query($marker, 10, null, self::VIEWER_B));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertCount(2, $group->items);
        self::assertSame(2, $group->total);
    }

    /**
     * `%`, `_`, `!` and `\` in the query match literally.
     */
    public function testContractEscapesLikeWildcards(): void
    {
        [$tenant] = $this->tenants();
        $marker   = $this->marker();
        foreach (['%x', '_y', '!z', '\\q', 'abc'] as $suffix) {
            $this->seed($tenant, $marker . $suffix);
        }
        $this->actAs($tenant, self::VIEWER_A);

        $all = $this->provider()->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $all);
        self::assertCount(5, $all->items);
        foreach (['%', '_', '!', '\\'] as $wildcard) {
            $group = $this->provider()->search($this->query($marker . $wildcard));
            self::assertInstanceOf(SearchGroup::class, $group, 'The row containing "' . $wildcard . '" must be found.');
            self::assertCount(1, $group->items, '"' . $wildcard . '" must be matched literally.');
        }
        self::assertNull($this->provider()->search($this->query($marker . '%%')));
    }

    /**
     * The limit holds, the total is the visible total, results pages do not overlap and stay bounded.
     */
    public function testContractLimitHoldsAndTotalIsVisible(): void
    {
        [$tenant] = $this->tenants();
        $marker   = $this->marker();
        for ($row = 0; $row < 6; ++$row) {
            $this->seed($tenant, $marker . 'n' . $row);
        }
        $this->actAs($tenant, self::VIEWER_A);
        $provider = $this->provider();

        $group = $provider->search($this->query($marker, 3));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertCount(3, $group->items);
        self::assertSame(6, $group->total);
        self::assertTrue($group->exactTotal);

        $second = $provider->searchPage($this->query($marker, 3), 2, 3);
        self::assertInstanceOf(SearchGroup::class, $second);
        self::assertCount(3, $second->items);
        self::assertSame([], array_intersect($this->urls($group), $this->urls($second)), 'Pages must not overlap.');
        self::assertNull($provider->searchPage($this->query($marker, 3), 3, 3));
        self::assertNull($provider->searchPage($this->query($marker, 3), 0, 3));
    }

    /**
     * One search sends at most queryBudget() statements (no N+1).
     */
    public function testContractStaysWithinTheQueryBudget(): void
    {
        [$tenant] = $this->tenants();
        $marker   = $this->marker();
        for ($row = 0; $row < 5; ++$row) {
            $this->seed($tenant, $marker . 'q' . $row);
        }
        $this->actAs($tenant, self::VIEWER_A);
        $provider = $this->provider();

        $counter = $this->queryCounter();
        $counter->reset();
        $group = $provider->search($this->query($marker, 5));
        $count = $counter->count();

        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertCount(5, $group->items);
        self::assertGreaterThan(0, $count, 'The test connection must count queries (QueryCountingMiddleware).');
        self::assertLessThanOrEqual($this->queryBudget(), $count, "Queries of one search:\n" . implode("\n", $counter->queries()));
    }

    /**
     * One provider instance, two viewers alternately: each sees only its rows, every time.
     */
    public function testContractIsWorkerSafeForAlternatingViewers(): void
    {
        [$first, $second] = $this->tenants();
        $marker           = $this->marker();
        $this->seed($first, $marker . 'a');
        $this->seed($second, $marker . 'b');
        $this->seed($second, $marker . 'c');
        $provider = $this->provider();

        $seen = [];
        for ($round = 0; $round < 2; ++$round) {
            $this->actAs($first, self::VIEWER_A);
            $seen[self::VIEWER_A][] = $this->urls($provider->search($this->query($marker)));
            $this->actAs($second, self::VIEWER_B);
            $seen[self::VIEWER_B][] = $this->urls($provider->search($this->query($marker, 10, null, self::VIEWER_B)));
        }

        self::assertCount(1, $seen[self::VIEWER_A][0]);
        self::assertCount(2, $seen[self::VIEWER_B][0]);
        self::assertSame($seen[self::VIEWER_A][0], $seen[self::VIEWER_A][1]);
        self::assertSame($seen[self::VIEWER_B][0], $seen[self::VIEWER_B][1]);
        self::assertSame([], array_intersect($seen[self::VIEWER_A][0], $seen[self::VIEWER_B][0]));
    }

    /**
     * @return list<string>
     */
    private function urls(?SearchGroup $group): array
    {
        return $group instanceof SearchGroup ? array_map(static fn (SearchItem $item): string => $item->url, $group->items) : [];
    }
}
