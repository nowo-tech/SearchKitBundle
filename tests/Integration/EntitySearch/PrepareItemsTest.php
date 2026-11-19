<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Integration\EntitySearch;

use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use LogicException;
use Nowo\SearchKitBundle\Event\SearchItemVisibilityEvent;
use Nowo\SearchKitBundle\Event\SearchKitEvents;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Model\SearchSubject;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchProvider;
use Nowo\SearchKitBundle\Provider\Entity\SearchItemContext;
use Nowo\SearchKitBundle\Test\Internal\RenderRecorder;
use Nowo\SearchKitBundle\Tests\Support\Audit\AuditContract;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\DbalNoteDefinition;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\NoteDatabase;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\PreparedNoteDefinition;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\StubDefinition;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\Viewer;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function array_column;
use function bin2hex;
use function random_bytes;

/**
 * 1.10.0: EntitySearchDefinition::prepareItems() / toPreparedItem() and the SearchItemContext, on the test database.
 */
#[Group('database')]
final class PrepareItemsTest extends TestCase
{
    private NoteDatabase $db;

    private string $marker;

    protected function setUp(): void
    {
        $this->db     = NoteDatabase::get();
        $this->marker = 'ski' . bin2hex(random_bytes(6));
    }

    public function testShownRowsArePreparedOnceAfterTheHostEventAndThePageCut(): void
    {
        $ids = [];
        for ($row = 0; $row < 4; ++$row) {
            $ids[] = (string) $this->db->insert('tenant-a', $this->marker . ' ' . $row);
        }
        $this->db->insert('tenant-a', $this->marker . ' hidden', false, true);
        $this->db->insert('tenant-a', $this->marker . ' archived', true);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SearchKitEvents::ITEM_VISIBILITY, static function (SearchItemVisibilityEvent $event) use ($ids): void {
            $event->deny(new SearchSubject('notes', $ids[0]));
        });
        $recorder = $this->recorder(new DbalNoteDefinition($this->db->connection, new Viewer('tenant-a', 'alice')));

        $group = (new EntitySearchProvider($recorder, $dispatcher))->search($this->query($this->marker, 2));

        self::assertSame(3, $group?->total);
        self::assertCount(1, $recorder->prepared);
        self::assertSame([$ids[1], $ids[2]], $recorder->prepared[0]['ids'], 'Only the shown rows: not denied, hidden, archived or beyond the page.');
        self::assertSame([$ids[1], $ids[2]], array_column($recorder->rendered, 'id'));
        self::assertSame(0, $recorder->prepared[0]['queries'], 'The default no-op sends no statement.');
    }

    public function testNothingIsPreparedWhenNoRowIsShown(): void
    {
        $this->db->insert('tenant-a', $this->marker . ' hidden', false, true);
        $recorder = $this->recorder(new DbalNoteDefinition($this->db->connection, new Viewer('tenant-a', 'alice')));
        $provider = new EntitySearchProvider($recorder);

        self::assertNull($provider->search($this->query($this->marker)));
        self::assertNull($provider->search($this->query($this->marker . 'none')));
        self::assertNull($provider->searchPage($this->query($this->marker), 2, 10));
        self::assertSame([], $recorder->prepared);
    }

    public function testEveryRenderGetsANewContext(): void
    {
        $id       = $this->db->insert('tenant-a', $this->marker . ' kept');
        $viewer   = new Viewer('tenant-a', 'alice');
        $recorder = $this->recorder(new PreparedNoteDefinition($this->db->connection, $viewer));
        $provider = new EntitySearchProvider($recorder);
        $resolve  = new SearchReference('/notes/' . $id, 'notes', 'note_show', ['id' => (string) $id]);

        foreach ([1, 2] as $round) {
            $group = $provider->search($this->query($this->marker));
            self::assertSame($this->marker . ' kept', $group?->items[0]->title, 'Search ' . $round . '.');
        }
        self::assertSame($this->marker . ' kept', $provider->resolve($resolve, 'alice', null)?->title);

        self::assertCount(3, $recorder->prepared);
        self::assertNotSame($recorder->prepared[0]['context'], $recorder->prepared[1]['context'], 'A context must never be reused.');
        self::assertNotSame($recorder->prepared[1]['context'], $recorder->prepared[2]['context']);
        self::assertSame([(string) $id], $recorder->prepared[2]['ids'], 'A resolved recent is prepared with its row.');
        self::assertSame(1, $recorder->prepared[0]['queries']);
    }

    public function testDefinitionMustOverrideToItemOrToPreparedItem(): void
    {
        $this->db->insert('tenant-a', $this->marker);
        $definition = new class extends EntitySearchDefinition {
            public function group(): string
            {
                return 'bare';
            }

            public function label(SearchQuery $query): string
            {
                return 'Bare';
            }

            public function tenant(SearchQuery $query): string
            {
                return 'tenant-a';
            }

            public function featureEnabled(int|string $tenant, SearchQuery $query): bool
            {
                return true;
            }

            public function searchableFields(): array
            {
                return ['n.title'];
            }

            public function baseQuery(int|string $tenant, SearchQuery $query): DbalQueryBuilder
            {
                return NoteDatabase::get()->connection->createQueryBuilder()->select('n.id')->from(NoteDatabase::TABLE, 'n')->where('n.tenant = :tenant')->setParameter('tenant', $tenant);
            }

            public function canView(array|object $row, int|string $tenant, SearchQuery $query): bool
            {
                return true;
            }

            public function seeAllUrl(SearchQuery $query): ?string
            {
                return null;
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('toItem() or toPreparedItem()');
        (new EntitySearchProvider($definition))->search($this->query($this->marker));
    }

    public function testContextKeepsValuesOfOneRender(): void
    {
        $context = new SearchItemContext();
        self::assertFalse($context->has('titles'));
        self::assertSame('none', $context->get('titles', 'none'));
        $context->set('titles', null);
        self::assertTrue($context->has('titles'));
        self::assertNull($context->get('titles', 'none'));
        $context->set('titles', ['1' => 'One']);
        self::assertSame(['1' => 'One'], $context->get('titles'));
    }

    public function testRecorderForwardsTheHooksItDoesNotRecord(): void
    {
        $id       = $this->db->insert('tenant-a', $this->marker);
        $stub     = new StubDefinition();
        $recorder = $this->recorder($stub);
        $query    = $this->query($this->marker);
        $builder  = $stub->baseQuery('tenant-a', $query);

        self::assertFalse($recorder->applyScope($builder, 'owner:x', 'tenant-a', $query));
        self::assertTrue($recorder->canView(['id' => $id], 'tenant-a', $query));
        self::assertSame('/stub/' . $id, $recorder->toItem(['id' => $id, 'title' => 't'], $query)->url);
        self::assertFalse($recorder->sensitive());
        $recorder->prepareItems([['id' => $id]], $query, new SearchItemContext());
        $recorder->reset();
        self::assertSame([], $recorder->prepared);
        self::assertSame([], $recorder->rendered);
    }

    public function testTheKitFailsAPrepareItemsWithNPlusOne(): void
    {
        $case         = new PreparedNoteContractTest('testContractPrepareItemsKeepsTheQueryBudget');
        $case->perRow = true;

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Statements sent by prepareItems()');
        $case->testContractPrepareItemsKeepsTheQueryBudget();
    }

    public function testTheKitRunsThePrepareChecksWithoutRecents(): void
    {
        foreach (['testContractPrepareItemsGetsOnlyTheShownRows', 'testContractPrepareItemsRunsForResolvedRecents', 'testContractPrepareItemsKeepsTheQueryBudget'] as $method) {
            (new AuditContract($method))->{$method}();
        }
        $this->addToAssertionCount(1);
    }

    public function testTheKitsDefaultSeedArchivedFails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('hasArchivedState()');
        (new PreparedNoteContractTest('testContractNeverShowsArchivedRows'))->defaultSeedArchived();
    }

    public function testTheKitSkipsTheArchivedCheckWithoutAnArchivedState(): void
    {
        $this->expectException(SkippedWithMessageException::class);
        $this->expectExceptionMessage('hasArchivedState() is false');
        (new PreparedNoteContractTest('testContractNeverShowsArchivedRows'))->testContractNeverShowsArchivedRows();
    }

    private function recorder(EntitySearchDefinition $definition): RenderRecorder
    {
        return new RenderRecorder($definition, $this->db->counter);
    }

    private function query(string $text, int $limit = 10): SearchQuery
    {
        return new SearchQuery($text, null, $limit, null, false, 'alice', [], 'en');
    }
}
