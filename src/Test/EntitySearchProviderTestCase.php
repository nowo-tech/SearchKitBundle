<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Test;

use Nowo\SearchKitBundle\Event\SearchItemVisibilityEvent;
use Nowo\SearchKitBundle\Event\SearchKitEvents;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Model\SearchSubject;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchProvider;
use Nowo\SearchKitBundle\Search\Group\SearchGroupSettings;
use Nowo\SearchKitBundle\Test\Internal\RenderRecorder;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function array_intersect;
use function array_map;
use function array_values;
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
 * - no row of another tenant, no row the access check hides and no archived row is ever shown or counted, and hidden
 *   rows filling the candidate window change neither the total nor its exactness;
 * - recents resolve only visible rows of the viewer's tenant with the same URL and kind;
 * - a sensitive group is off while recents keep titles in the browser;
 * - LIKE wildcards (`%`, `_`, `!`, `\`) in the query are matched literally;
 * - the limit holds, the total is the visible total, and the results page is bounded;
 * - a definition that bounds its own candidates reports an inexact total, for that search only (1.11.0, optional);
 * - one search stays within the query budget (no N+1);
 * - prepareItems() runs once per render with exactly the shown rows, before they are rendered (1.10.0);
 * - one provider instance serves two viewers alternately with no state kept, and one definition instance renders
 *   exactly like a fresh one (FrankenPHP worker mode).
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
     *
     * @return int|string The row id (what subjectId() and referenceId() give)
     */
    abstract protected function seed(int|string $tenant, string $text): int|string;

    /**
     * Inserts one row of the tenant that the **per-row access check** (canView() / filterVisible()) hides from the
     * viewers of actAs() — e.g. a private record of another user, a board they are not a member of — and that the SQL
     * of baseQuery() does not filter out.
     *
     * @param int|string $tenant The tenant
     * @param string $text Text to put in a searchable field
     *
     * @return int|string The row id
     */
    abstract protected function seedHidden(int|string $tenant, string $text): int|string;

    /**
     * Inserts one archived (or soft-deleted) row of the tenant, which baseQuery() filters out in SQL. Only called while
     * hasArchivedState() is true (1.10.0: no longer abstract; the default fails the test, so override it or declare
     * hasArchivedState() false).
     *
     * @param int|string $tenant The tenant
     * @param string $text Text to put in a searchable field
     *
     * @return int|string The row id
     */
    protected function seedArchived(int|string $tenant, string $text): int|string
    {
        self::fail('Override seedArchived(), or return false from hasArchivedState() when the records have no archived or deleted state.');
    }

    /**
     * Changes the searchable text of a seeded row (1.10.0, optional): the worker-safety check then also renders a row
     * whose display data changed between two renders on one definition instance. Return false when the test cannot do
     * it (default): the check still compares every render of the shared instance with a fresh one.
     *
     * @param int|string $id A row id returned by seed()
     * @param string $text The new text (contains the old one, so the row still matches)
     *
     * @return bool Whether the text was changed
     */
    protected function changeSeededText(int|string $id, string $text): bool
    {
        return false;
    }

    /**
     * Inserts rows of the tenant so that a search for `$text` hits the definition's own bound on its candidates (1.11.0,
     * optional): the definition calls SearchCandidateContext::markPartial() in candidateQuery() or filterCandidates()
     * for that search — e.g. more boards whose tasks contain `$text` than the boards it scans — and still shows at
     * least one row containing `$text`. The kit then asserts that the total is inexact, that the bound shows no other
     * tenant's row, and that the flag does not leak into the next search on the same provider instance.
     *
     * The kit only calls it with the first tenant of tenants(); a search for a fresh row in the second tenant must not
     * hit the bound. Make the bound reachable only by what this hook seeds (e.g. count only the boards that match the
     * text), or reset the database between tests, so that the other contract tests still see exact totals. Return
     * false (default) when the definition has no bound: the check is skipped.
     *
     * @param int|string $tenant The first tenant
     * @param string $text Text to put in a searchable field of the rows
     *
     * @return bool Whether the rows were seeded
     */
    protected function seedBeyondTheBound(int|string $tenant, string $text): bool
    {
        return false;
    }

    /**
     * Whether baseQuery() itself filters out every row seedHidden() creates (1.10.1), so rows hidden from the viewer
     * never take places in the candidate window. True: the kit asserts that hidden rows change nothing at all (rows,
     * total, exactness). False: hidden rows may make the total inexact (`exactTotal` false) and push visible rows out
     * of the window, but never leave a smaller total marked exact.
     *
     * Default: the definition's sensitive() — a sensitive group must filter in SQL. Override it to false only when
     * the visibility can only be decided in PHP or by a host event, and document that the group accepts the count
     * side channel of the candidate window.
     */
    protected function hiddenRowsFilteredInSql(): bool
    {
        return $this->definition()->sensitive();
    }

    /**
     * Whether the records have an archived or soft-deleted state that baseQuery() filters out (1.10.0). False: the
     * archived checks are skipped and seedArchived() is never called. Default: true.
     */
    protected function hasArchivedState(): bool
    {
        return true;
    }

    /**
     * The recent reference the browser would store for a row (its URL, the kind — usually the group key — and the
     * route with its parameters), as RecentResolver builds it.
     *
     * @param int|string $id A row id returned by a seed hook
     */
    abstract protected function reference(int|string $id): SearchReference;

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
     * Whether the definition revalidates recents (idField() and referenceId()); false: every reference must be
     * dropped (null), even a visible row's.
     */
    protected function supportsRecents(): bool
    {
        return true;
    }

    /**
     * Whether the group's titles are sensitive: must equal the definition's sensitive().
     */
    protected function sensitiveTitles(): bool
    {
        return false;
    }

    /**
     * Most SQL statements of one search (default 2: the search query and one batched access check), prepareItems()
     * included.
     */
    protected function queryBudget(): int
    {
        return 2;
    }

    /**
     * Most SQL statements of one prepareItems() call (1.10.0; default 1: one batched load of the shown rows' display
     * data). The default no-op of EntitySearchDefinition must send none.
     */
    protected function prepareItemsBudget(): int
    {
        return 1;
    }

    /**
     * The provider under test (SearchKit's generic provider around definition()).
     *
     * @param EventDispatcher|null $dispatcher The dispatcher of the host event (null: a new empty one)
     * @param bool $recentsSafe False: SearchKit keeps recents in the browser without revalidation
     */
    protected function provider(?EventDispatcher $dispatcher = null, bool $recentsSafe = true): EntitySearchProvider
    {
        return new EntitySearchProvider($this->definition(), $dispatcher ?? new EventDispatcher(), null, new SearchGroupSettings(), $recentsSafe);
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
     * One batched host event; a deny wins over a later allow and over an earlier listener that allows and stops
     * propagation, and a denied row is neither shown nor counted.
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
            foreach ($event->subjects() as $subject) {
                $event->allow($subject);
            }
            $event->stopPropagation();
        }, 10);
        $dispatcher->addListener(SearchKitEvents::ITEM_VISIBILITY, static function (SearchItemVisibilityEvent $event): void {
            $event->deny($event->subjects()[0]);
        });
        $group = $this->provider($dispatcher)->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertCount(1, $group->items);
        self::assertSame(1, $group->total, 'A denied row must not be counted.');
    }

    /**
     * Rows the access check hides and archived rows are never shown or counted.
     */
    public function testContractNeverShowsHiddenOrArchivedRows(): void
    {
        [$tenant] = $this->tenants();
        $marker   = $this->marker();
        $this->seed($tenant, $marker . 'v');
        $this->seedHidden($tenant, $marker . 'h');
        if ($this->hasArchivedState()) {
            $this->archivedRow($tenant, $marker . 'a');
        }
        $this->actAs($tenant, self::VIEWER_A);

        $group = $this->provider()->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertCount(1, $group->items, 'Hidden and archived rows must not be shown.');
        self::assertSame(1, $group->total, 'Hidden and archived rows must not be counted.');
        self::assertNull($this->provider()->search($this->query($marker . 'h')), 'A hidden row alone must give null.');
        if ($this->hasArchivedState()) {
            self::assertNull($this->provider()->search($this->query($marker . 'a')), 'An archived row alone must give null.');
        }
    }

    /**
     * Archived rows are filtered in SQL: never shown, counted or resolved (skipped without an archived state).
     */
    public function testContractNeverShowsArchivedRows(): void
    {
        if (!$this->hasArchivedState()) {
            self::markTestSkipped('The definition declares no archived or deleted state (hasArchivedState() is false).');
        }
        [$tenant] = $this->tenants();
        $marker   = $this->marker();
        $this->seed($tenant, $marker . 'v');
        $archived = $this->archivedRow($tenant, $marker . 'a');
        $this->actAs($tenant, self::VIEWER_A);
        $provider = $this->provider();

        $group = $provider->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertCount(1, $group->items, 'An archived row must not be shown.');
        self::assertSame(1, $group->total, 'An archived row must not be counted.');
        self::assertNull($provider->search($this->query($marker . 'a')), 'An archived row alone must give null.');
        self::assertNull($provider->resolve($this->reference($archived), self::VIEWER_A, null), 'An archived row must not resolve.');
    }

    /**
     * Hidden rows that fill the candidate window change neither the total nor whether it is exact (no count oracle).
     */
    public function testContractHiddenRowsBeyondTheWindowChangeNothing(): void
    {
        $candidates = $this->definition()->maxCandidates();
        if ($candidates < 2 || $candidates > 500) {
            self::markTestSkipped('maxCandidates() must be 2 to 500 for this check.');
        }
        [$tenant] = $this->tenants();
        $this->actAs($tenant, self::VIEWER_A);
        $plain = $this->marker();
        $this->seed($tenant, $plain . 'v');
        $reference = $this->provider()->search($this->query($plain));
        self::assertInstanceOf(SearchGroup::class, $reference);

        $marker = $this->marker();
        $this->seed($tenant, $marker . 'first');
        for ($row = 0; $row < $candidates; ++$row) {
            $this->seedHidden($tenant, $marker . 'h' . $row);
        }
        $this->seed($tenant, $marker . 'last');
        $group = $this->provider()->search($this->query($marker));

        self::assertInstanceOf(SearchGroup::class, $group, 'A visible row must still be found.');
        self::assertSame(count($group->items), $group->total, 'Only visible rows are counted.');
        self::assertLessThanOrEqual(2, $group->total);
        if ($this->hiddenRowsFilteredInSql()) {
            self::assertSame($reference->exactTotal, $group->exactTotal, 'Hidden rows changed the results: the definition hides rows only in PHP, so they take places in the candidate window (a count side channel). Either filter them in baseQuery(), or — when only PHP or a host event can decide the visibility — override hiddenRowsFilteredInSql() to return false and document that the group accepts that side channel.');
        } else {
            self::assertTrue($group->exactTotal === $reference->exactTotal || !$group->exactTotal, 'Hidden rows may only make the total inexact.');
        }
    }

    /**
     * 1.10.1: rows hidden from the viewer and **newer** (or older: the kit does not know the order) than the visible
     * ones, in a small candidate window (at most 10 rows), either change nothing — the definition filters them in SQL,
     * required by default for sensitive groups — or make the total inexact: a count marked exact never shrinks because
     * of rows the viewer may not see. Visible rows they push out of the window stay missing (only SQL filtering avoids
     * that).
     */
    public function testContractHiddenRowsAroundVisibleOnesNeverShrinkAnExactTotal(): void
    {
        [$tenant] = $this->tenants();
        $this->actAs($tenant, self::VIEWER_A);
        $window   = min(10, $this->definition()->maxCandidates());
        $provider = new EntitySearchProvider(new RenderRecorder($this->definition(), $this->queryCounter(), $window), new EventDispatcher());

        $plain = $this->marker();
        for ($row = 0; $row < 6; ++$row) {
            $this->seed($tenant, $plain . 'v' . $row);
        }
        $reference = $provider->search($this->query($plain, 10));
        self::assertInstanceOf(SearchGroup::class, $reference);

        $marker = $this->marker();
        for ($row = 0; $row < 7; ++$row) {
            $this->seedHidden($tenant, $marker . 'o' . $row);
        }
        for ($row = 0; $row < 6; ++$row) {
            $this->seed($tenant, $marker . 'v' . $row);
        }
        for ($row = 0; $row < 7; ++$row) {
            $this->seedHidden($tenant, $marker . 'n' . $row);
        }
        $group = $provider->search($this->query($marker, 10));

        $unchanged = $group instanceof SearchGroup
            && count($group->items) === count($reference->items)
            && $group->total === $reference->total
            && $group->exactTotal === $reference->exactTotal;
        if ($this->hiddenRowsFilteredInSql()) {
            self::assertTrue($unchanged, 'Hidden rows changed the results: the definition hides rows only in PHP, so they take places in the candidate window (a count side channel). Either filter them in baseQuery(), or — when only PHP or a host event can decide the visibility — override hiddenRowsFilteredInSql() to return false and document that the group accepts that side channel.');

            return;
        }
        self::assertTrue($unchanged || ($group instanceof SearchGroup ? !$group->exactTotal : true), 'Rows hidden only in PHP filled the window: the total must not be marked exact.');
        if ($group instanceof SearchGroup) {
            self::assertLessThanOrEqual(count($reference->items), count($group->items), 'Hidden rows must never be shown.');
        }
    }

    /**
     * Recents: a visible row resolves (when the definition revalidates them); a hidden, archived or other-tenant row,
     * another URL or kind, no tenant and the feature off give null.
     */
    public function testContractRecentsResolveOnlyVisibleRows(): void
    {
        [$tenant, $other] = $this->tenants();
        $marker           = $this->marker();
        $visible          = $this->reference($this->seed($tenant, $marker . 'v'));
        $hidden           = $this->reference($this->seedHidden($tenant, $marker . 'h'));
        $archived         = $this->hasArchivedState() ? $this->reference($this->archivedRow($tenant, $marker . 'a')) : null;
        $foreign          = $this->reference($this->seed($other, $marker . 'o'));
        $this->actAs($tenant, self::VIEWER_A);
        $provider = $this->provider();

        $item = $provider->resolve($visible, self::VIEWER_A, null);
        if ($this->supportsRecents()) {
            self::assertInstanceOf(SearchItem::class, $item, 'A visible row must resolve.');
            self::assertSame($visible->url, $item->url);
        } else {
            self::assertNull($item, 'Without recents support every reference is dropped.');
        }
        self::assertNull($provider->resolve($hidden, self::VIEWER_A, null), 'Hidden row.');
        if ($archived instanceof SearchReference) {
            self::assertNull($provider->resolve($archived, self::VIEWER_A, null), 'Archived row.');
        }
        self::assertNull($provider->resolve($foreign, self::VIEWER_A, null), 'Row of another tenant.');
        self::assertNull($provider->resolve(new SearchReference($visible->url . 'x', $visible->kind, $visible->route, $visible->routeParameters), self::VIEWER_A, null), 'Another URL.');
        self::assertNull($provider->resolve(new SearchReference($visible->url, $visible->kind . 'x', $visible->route, $visible->routeParameters), self::VIEWER_A, null), 'Another kind.');
        $this->actAs(null, self::VIEWER_A);
        self::assertNull($provider->resolve($visible, self::VIEWER_A, null), 'No tenant.');
        $this->actAs($tenant, self::VIEWER_A);
        $this->disableFeature();
        self::assertNull($provider->resolve($visible, self::VIEWER_A, null), 'Feature off.');
    }

    /**
     * sensitive() matches the test's declaration, and a sensitive group is off while recents keep titles.
     */
    public function testContractSensitiveGroupsFollowTheRecentsMode(): void
    {
        self::assertSame($this->sensitiveTitles(), $this->definition()->sensitive(), 'sensitive() must match sensitiveTitles().');
        [$tenant] = $this->tenants();
        $marker   = $this->marker();
        $this->seed($tenant, $marker);
        $this->actAs($tenant, self::VIEWER_A);

        self::assertInstanceOf(SearchGroup::class, $this->provider(null, true)->search($this->query($marker)));
        $unsafe = $this->provider(null, false)->search($this->query($marker));
        if ($this->sensitiveTitles()) {
            self::assertNull($unsafe, 'A sensitive group must be off while recents are not revalidated.');
        } else {
            self::assertInstanceOf(SearchGroup::class, $unsafe);
        }
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
     * 1.11.0: a definition that bounds its own candidates (SearchCandidateContext::markPartial()) reports an inexact
     * total, in the palette and on the results page, still shows no other tenant's row, and the flag belongs to that
     * one search: the next search on the same provider instance is exact again. Skipped unless seedBeyondTheBound()
     * seeds the rows.
     */
    public function testContractBoundedCandidatesGiveAnInexactTotal(): void
    {
        [$tenant, $other] = $this->tenants();
        $marker           = $this->marker();
        if (!$this->seedBeyondTheBound($tenant, $marker)) {
            self::markTestSkipped('The definition does not bound its candidates (seedBeyondTheBound() returns false).');
        }
        $foreign = $this->reference($this->seed($other, $marker . 'other'))->url;
        $this->actAs($tenant, self::VIEWER_A);
        $provider = $this->provider();

        $group = $provider->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $group, 'seedBeyondTheBound() must still leave a visible row containing the text.');
        self::assertFalse($group->exactTotal, 'The definition hit its bound (seedBeyondTheBound()): candidateQuery() or filterCandidates() must call $context->markPartial(), so the total is not claimed exact.');
        self::assertNotContains($foreign, $this->urls($group), 'A bounded definition showed another tenant\'s row.');
        $page = $provider->searchPage($this->query($marker), 1, 10);
        self::assertInstanceOf(SearchGroup::class, $page);
        self::assertFalse($page->exactTotal, 'The results page of a bounded search must not claim an exact total.');

        $fresh = $this->marker();
        $this->seed($other, $fresh);
        $this->actAs($other, self::VIEWER_B);
        $next = $provider->search($this->query($fresh, 10, null, self::VIEWER_B));
        self::assertInstanceOf(SearchGroup::class, $next);
        self::assertTrue($next->exactTotal, 'The partial flag leaked into the next search: keep it in the SearchCandidateContext of that search, never in a property of the definition.');
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
     * prepareItems() runs once per page with exactly the shown rows — never a row the access check hides, a row the
     * host denies or a row beyond the page — right before those rows are rendered, with the context
     * that toPreparedItem() then gets; and not at all when nothing is shown.
     */
    public function testContractPrepareItemsGetsOnlyTheShownRows(): void
    {
        [$tenant] = $this->tenants();
        $marker   = $this->marker();
        $visible  = [];
        for ($row = 0; $row < 4; ++$row) {
            $visible[] = (string) $this->seed($tenant, $marker . 'p' . $row);
        }
        $denied   = $visible[1];
        $excluded = [$denied, (string) $this->seedHidden($tenant, $marker . 'h')];
        $this->actAs($tenant, self::VIEWER_A);
        $type       = $this->definition()->subjectType();
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SearchKitEvents::ITEM_VISIBILITY, static function (SearchItemVisibilityEvent $event) use ($type, $denied): void {
            $event->deny(new SearchSubject($type, $denied));
        });
        $recorder = $this->recorder();
        $provider = $this->recordingProvider($recorder, $dispatcher);

        $first = $provider->search($this->query($marker, 2));
        self::assertInstanceOf(SearchGroup::class, $first);
        self::assertCount(2, $first->items);
        $firstIds = $this->assertRenderedOnce($recorder, 2);
        self::assertSame([], array_intersect($firstIds, $excluded), 'prepareItems() must never get a hidden or denied row.');
        self::assertSame($firstIds, array_values(array_intersect($firstIds, $visible)));

        $recorder->reset();
        $second = $provider->searchPage($this->query($marker, 2), 2, 2);
        self::assertInstanceOf(SearchGroup::class, $second);
        self::assertCount(1, $second->items);
        $secondIds = $this->assertRenderedOnce($recorder, 1);
        self::assertSame([], array_intersect($secondIds, [...$excluded, ...$firstIds]), 'prepareItems() must get only the rows of its page.');

        $recorder->reset();
        self::assertNull($provider->search($this->query($marker . 'h')));
        self::assertNull($provider->search($this->query($marker . 'p1')));
        self::assertSame([], $recorder->prepared, 'prepareItems() must not run when no row is shown.');
        self::assertSame([], $recorder->rendered);
    }

    /**
     * A resolved recent is rendered the same way: one prepareItems() call with that row; none for a dropped one.
     */
    public function testContractPrepareItemsRunsForResolvedRecents(): void
    {
        [$tenant] = $this->tenants();
        $marker   = $this->marker();
        $visible  = $this->seed($tenant, $marker . 'v');
        $hidden   = $this->seedHidden($tenant, $marker . 'h');
        $this->actAs($tenant, self::VIEWER_A);
        $recorder = $this->recorder();
        $provider = $this->recordingProvider($recorder);

        self::assertNull($provider->resolve($this->reference($hidden), self::VIEWER_A, null));
        self::assertSame([], $recorder->prepared, 'prepareItems() must not run for a dropped recent.');

        $item = $provider->resolve($this->reference($visible), self::VIEWER_A, null);
        if (!$this->supportsRecents()) {
            self::assertNull($item);
            self::assertSame([], $recorder->prepared, 'prepareItems() must not run for a dropped recent.');

            return;
        }
        self::assertInstanceOf(SearchItem::class, $item);
        self::assertSame([(string) $visible], $this->assertRenderedOnce($recorder, 1));
    }

    /**
     * prepareItems() keeps the query budget: at most prepareItemsBudget() statements, none for the default no-op, and
     * the whole search still within queryBudget().
     */
    public function testContractPrepareItemsKeepsTheQueryBudget(): void
    {
        [$tenant] = $this->tenants();
        $marker   = $this->marker();
        for ($row = 0; $row < 5; ++$row) {
            $this->seed($tenant, $marker . 'b' . $row);
        }
        $this->actAs($tenant, self::VIEWER_A);
        $recorder = $this->recorder();
        $provider = $this->recordingProvider($recorder);

        $counter = $this->queryCounter();
        $counter->reset();
        $group = $provider->search($this->query($marker, 5));
        $count = $counter->count();

        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertCount(5, $group->items);
        self::assertCount(1, $recorder->prepared);
        $prepareQueries = $recorder->prepared[0]['queries'];
        $default        = (new ReflectionMethod($this->definition(), 'prepareItems'))->getDeclaringClass()->getName() === EntitySearchDefinition::class;
        self::assertLessThanOrEqual($default ? 0 : $this->prepareItemsBudget(), $prepareQueries, 'Statements sent by prepareItems().');
        self::assertLessThanOrEqual($this->queryBudget(), $count, "Queries of one search:\n" . implode("\n", $counter->queries()));
    }

    /**
     * 1.10.0: one definition instance serves alternating viewers and changed rows exactly like a fresh instance per
     * render, so no display data (prepareItems() or otherwise) is memoised on the instance. Without
     * changeSeededText() the changed-row render is not checked (the kit cannot change a row generically).
     */
    public function testContractReusedDefinitionRendersLikeAFreshOne(): void
    {
        [$first, $second] = $this->tenants();
        $marker           = $this->marker();
        $row              = $this->seed($first, $marker . 'a');
        $this->seed($second, $marker . 'b');
        $this->seed($second, $marker . 'c');
        $shared = $this->provider();
        $render = function (int|string $tenant, string $viewer, string $why) use ($shared, $marker): void {
            $this->actAs($tenant, $viewer);
            $query = $this->query($marker, 10, null, $viewer);
            self::assertEquals($this->provider()->search($query)?->items, $shared->search($query)?->items, 'A reused definition must render like a fresh one (' . $why . '): keep display data in the SearchItemContext, never in a property.');
        };

        $render($first, self::VIEWER_A, 'first viewer');
        $render($second, self::VIEWER_B, 'second viewer');
        $render($first, self::VIEWER_A, 'first viewer again');
        if ($this->changeSeededText($row, $marker . 'a changed')) {
            $render($first, self::VIEWER_A, 'changed row');
        }
    }

    /**
     * seedArchived(), with a hint when a 1.9-style test skips it instead of declaring hasArchivedState() false.
     */
    private function archivedRow(int|string $tenant, string $text): int|string
    {
        try {
            return $this->seedArchived($tenant, $text);
        } catch (SkippedTest $skipped) {
            self::markTestSkipped($skipped->getMessage() . ' (seedArchived() skipped: if the records have no archived or deleted state, return false from hasArchivedState() so the other checks of this test run.)');
        }
    }

    /**
     * The definition under test, with its prepareItems() / toPreparedItem() calls recorded.
     */
    private function recorder(): RenderRecorder
    {
        return new RenderRecorder($this->definition(), $this->queryCounter());
    }

    private function recordingProvider(RenderRecorder $recorder, ?EventDispatcher $dispatcher = null): EntitySearchProvider
    {
        return new EntitySearchProvider($recorder, $dispatcher ?? new EventDispatcher());
    }

    /**
     * One prepareItems() call with `$rows` rows, then one toPreparedItem() per row, in order, after it and with its
     * context.
     *
     * @return list<string> The prepared ids
     */
    private function assertRenderedOnce(RenderRecorder $recorder, int $rows): array
    {
        self::assertCount(1, $recorder->prepared, 'prepareItems() must run exactly once per render.');
        $prepared = $recorder->prepared[0];
        self::assertCount($rows, $prepared['ids'], 'prepareItems() must get exactly the shown rows.');
        self::assertSame($prepared['ids'], array_map(static fn (array $call): string => $call['id'], $recorder->rendered), 'toPreparedItem() must render exactly the prepared rows, in order.');
        foreach ($recorder->rendered as $call) {
            self::assertSame(1, $call['after'], 'prepareItems() must run before the rows are rendered.');
            self::assertSame($prepared['context'], $call['context'], 'toPreparedItem() must get the context of prepareItems().');
        }

        return $prepared['ids'];
    }

    /**
     * @return list<string>
     */
    private function urls(?SearchGroup $group): array
    {
        return $group instanceof SearchGroup ? array_map(static fn (SearchItem $item): string => $item->url, $group->items) : [];
    }
}
