<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Integration\Audit;

use LogicException;
use Nowo\SearchKitBundle\Event\SearchItemVisibilityEvent;
use Nowo\SearchKitBundle\Event\SearchKitEvents;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchProvider;
use Nowo\SearchKitBundle\Search\Query\LikePattern;
use Nowo\SearchKitBundle\Tests\Support\Audit\AuditContract;
use Nowo\SearchKitBundle\Tests\Support\Audit\AuditNoteDefinition;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\NoteDatabase;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\Viewer;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function bin2hex;
use function count;
use function random_bytes;

/**
 * Regression tests of audit VF (SearchKit 1.9.0 provider base), on the test database.
 */
#[Group('database')]
final class AuditRegressionTest extends TestCase
{
    /**
     * VF-1: a listener that stops propagation cannot skip a later deny.
     */
    public function testStopPropagationCannotSkipALaterDeny(): void
    {
        $marker = $this->marker();
        NoteDatabase::get()->insert('tenant-a', $marker);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SearchKitEvents::ITEM_VISIBILITY, static function (SearchItemVisibilityEvent $event): void {
            $event->stopPropagation();
        }, 10);
        $dispatcher->addListener(SearchKitEvents::ITEM_VISIBILITY, static function (SearchItemVisibilityEvent $event): void {
            $event->denyAll();
        }, 0);

        self::assertNull($this->provider(new AuditNoteDefinition(NoteDatabase::get()->connection, $this->viewer()), $dispatcher)->search($this->query($marker)));
    }

    /**
     * VF-2, revised by VJ (1.10.1): hidden rows are never counted; a window the fetch filled — with hidden rows too —
     * gives an inexact total, and rows hidden in SQL change nothing.
     */
    public function testHiddenRowsDoNotChangeTheTotalOrItsExactness(): void
    {
        $db    = NoteDatabase::get();
        $plain = $this->marker();
        $db->insert('tenant-a', $plain);
        $mixed = $this->marker();
        $db->insert('tenant-a', $mixed);
        $db->insert('tenant-a', $mixed . 'x', false, true);
        $db->insert('tenant-a', $mixed . 'y', false, true);
        $provider = $this->provider(new AuditNoteDefinition($db->connection, $this->viewer(), true, true, 2));
        $inSql    = $this->provider(new AuditNoteDefinition($db->connection, $this->viewer(), true, true, 2, false, false, true));

        $a = $provider->search($this->query($plain));
        $b = $provider->search($this->query($mixed));

        self::assertSame([1, true], [$a?->total, $a?->exactTotal]);
        self::assertSame([1, false], [$b?->total, $b?->exactTotal], 'Hidden rows filled the window: a lower bound.');
        self::assertSame([1, true], [$inSql->search($this->query($mixed))?->total, $inSql->search($this->query($mixed))?->exactTotal], 'Rows hidden in SQL change nothing.');

        $full = $this->marker();
        $db->insert('tenant-a', $full . 'a');
        $db->insert('tenant-a', $full . 'b');
        $db->insert('tenant-a', $full . 'c');
        $c = $provider->search($this->query($full));
        self::assertSame([2, false], [$c?->total, $c?->exactTotal], 'Visible rows filling the window: a lower bound.');
    }

    /**
     * VJ (1.10.1): 7 hidden rows ahead of 6 visible ones in a 10-row window shrink the visible count to 3, which must
     * not be marked exact (it would let a viewer count rows they cannot see).
     */
    public function testHiddenRowsAheadOfVisibleOnesGiveAnInexactTotal(): void
    {
        $db     = NoteDatabase::get();
        $marker = $this->marker();
        for ($row = 0; $row < 7; ++$row) {
            $db->insert('tenant-a', $marker . 'h' . $row, false, true);
        }
        for ($row = 0; $row < 6; ++$row) {
            $db->insert('tenant-a', $marker . 'v' . $row);
        }

        $group = $this->provider(new AuditNoteDefinition($db->connection, $this->viewer(), true, true, 10))->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertSame([3, 3, false], [count($group->items), $group->total, $group->exactTotal]);

        $page = $this->provider(new AuditNoteDefinition($db->connection, $this->viewer(), true, true, 10))->searchPage($this->query($marker), 2, 2);
        self::assertInstanceOf(SearchGroup::class, $page);
        self::assertSame([1, 3, false], [count($page->items), $page->total, $page->exactTotal], 'The results page too.');

        $inSql = $this->provider(new AuditNoteDefinition($db->connection, $this->viewer(), true, true, 10, false, false, true))->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $inSql);
        self::assertSame([6, 6, true], [count($inSql->items), $inSql->total, $inSql->exactTotal], 'Filtered in SQL: exact and complete.');
    }

    /**
     * A listener exception reaches the caller (SearchKit's engine drops the group): never fail open.
     */
    public function testListenerExceptionPropagates(): void
    {
        $marker = $this->marker();
        NoteDatabase::get()->insert('tenant-a', $marker);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SearchKitEvents::ITEM_VISIBILITY, static function (): void {
            throw new RuntimeException('listener failure');
        });

        $this->expectException(RuntimeException::class);
        $this->provider(new AuditNoteDefinition(NoteDatabase::get()->connection, $this->viewer()), $dispatcher)->search($this->query($marker));
    }

    /**
     * VF-4: a reserved parameter bound by baseQuery() is refused, not overwritten.
     */
    public function testReservedParameterIsRefused(): void
    {
        $marker = $this->marker();
        NoteDatabase::get()->insert('tenant-a', $marker, false, false, 'bob');

        $this->expectException(LogicException::class);
        $this->provider(new AuditNoteDefinition(NoteDatabase::get()->connection, $this->viewer(), true, true, 100, true))->search($this->query($marker));
    }

    /**
     * VF-5: a trailing newline never passes a field name.
     */
    public function testFieldPatternRefusesATrailingNewline(): void
    {
        self::assertFalse(LikePattern::isField("n.title\n"));
    }

    /**
     * Backslash, bang, quote and underscore match literally on every platform.
     */
    public function testEscapingEdges(): void
    {
        $db     = NoteDatabase::get();
        $marker = $this->marker();
        foreach (['a\\%b', 'a\\\\b', 'a!!b', "a'b", 'A_B'] as $title) {
            $db->insert('tenant-a', $marker . $title);
        }
        $provider = $this->provider(new AuditNoteDefinition($db->connection, $this->viewer()));
        $expected = ['a\\%b' => 1, 'a\\\\b' => 1, 'a\\b' => 0, 'a!!b' => 1, 'a!b' => 0, "a'b" => 1, 'a_b' => 1, 'a\\_b' => 0];
        $found    = [];
        foreach ($expected as $text => $count) {
            $group        = $provider->search($this->query($marker . $text));
            $found[$text] = $group instanceof SearchGroup ? count($group->items) : 0;
        }

        self::assertSame($expected, $found);
    }

    /**
     * @return iterable<string, array{0: array<string, bool|int>, 1: non-empty-string}>
     */
    public static function brokenDefinitions(): iterable
    {
        yield 'no per-row access check' => [['checkHidden' => false], 'testContractNeverShowsHiddenOrArchivedRows'];
        yield 'no per-row access check, window' => [['checkHidden' => false], 'testContractHiddenRowsBeyondTheWindowChangeNothing'];
        yield 'no tenant condition' => [['tenantInSql' => false], 'testContractNeverShowsAnotherTenantsRows'];
        yield 'sensitive titles not declared' => [['sensitiveDefinition' => true], 'testContractSensitiveGroupsFollowTheRecentsMode'];
        yield 'hidden rows declared filtered in SQL, filtered in PHP' => [['declareHiddenInSql' => true], 'testContractHiddenRowsAroundVisibleOnesNeverShrinkAnExactTotal'];
        yield 'hidden rows declared filtered in SQL, filtered in PHP, full window' => [['declareHiddenInSql' => true], 'testContractHiddenRowsBeyondTheWindowChangeNothing'];
    }

    /**
     * VF-3: the contract kit fails a definition that skips its access check or its tenant condition.
     *
     * @param array<string, bool|int> $switches
     * @param non-empty-string $method
     */
    #[DataProvider('brokenDefinitions')]
    public function testTheKitFailsABrokenDefinition(array $switches, string $method): void
    {
        $case = $this->contract($method, $switches);

        $this->expectException(AssertionFailedError::class);
        $case->{$method}();
    }

    /**
     * The sound audit definition passes the kit, a sensitive one included; a one-row window skips the window check.
     */
    public function testTheKitPassesASoundDefinition(): void
    {
        foreach (['testContractNeverShowsHiddenOrArchivedRows', 'testContractHiddenRowsBeyondTheWindowChangeNothing', 'testContractNeverShowsAnotherTenantsRows', 'testContractRecentsResolveOnlyVisibleRows'] as $method) {
            $this->contract($method)->{$method}();
        }
        $this->contract('testContractSensitiveGroupsFollowTheRecentsMode', ['sensitiveDefinition' => true, 'sensitive' => true])->testContractSensitiveGroupsFollowTheRecentsMode();
        foreach ([[], ['hiddenInSql' => true, 'declareHiddenInSql' => true], ['hiddenInSql' => true]] as $switches) {
            $this->contract('testContractHiddenRowsAroundVisibleOnesNeverShrinkAnExactTotal', $switches)->testContractHiddenRowsAroundVisibleOnesNeverShrinkAnExactTotal();
            $this->contract('testContractHiddenRowsBeyondTheWindowChangeNothing', $switches)->testContractHiddenRowsBeyondTheWindowChangeNothing();
        }

        $this->expectException(SkippedWithMessageException::class);
        $this->contract('testContractHiddenRowsBeyondTheWindowChangeNothing', ['candidates' => 1])->testContractHiddenRowsBeyondTheWindowChangeNothing();
    }

    /**
     * @param non-empty-string $method
     * @param array<string, bool|int> $switches
     */
    private function contract(string $method, array $switches = []): AuditContract
    {
        $case = new AuditContract($method);
        foreach ($switches as $name => $value) {
            $case->{$name} = $value;
        }

        return $case;
    }

    private function provider(AuditNoteDefinition $definition, ?EventDispatcher $dispatcher = null): EntitySearchProvider
    {
        return new EntitySearchProvider($definition, $dispatcher ?? new EventDispatcher());
    }

    private function viewer(): Viewer
    {
        return new Viewer('tenant-a', 'u-a', true);
    }

    private function query(string $text): SearchQuery
    {
        return SearchQuery::forUserIdentifier('u-a', $text, 10);
    }

    private function marker(): string
    {
        return 'aud' . bin2hex(random_bytes(5));
    }
}
