<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Integration\EntitySearch;

use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use InvalidArgumentException;
use LogicException;
use Nowo\SearchKitBundle\Event\SearchItemVisibilityEvent;
use Nowo\SearchKitBundle\Event\SearchKitEvents;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchProvider;
use Nowo\SearchKitBundle\Search\Group\SearchGroupSettings;
use Nowo\SearchKitBundle\Search\Group\SensitiveGroupNotice;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\DbalNoteDefinition;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\Note;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\NoteDatabase;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\OrmNoteDefinition;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\StubDefinition;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\Viewer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Translation\IdentityTranslator;

use function array_map;
use function bin2hex;
use function random_bytes;

/**
 * Every branch of EntitySearchProvider on the test database (SQLite, or the CI's MySQL / MariaDB / PostgreSQL).
 */
#[Group('database')]
final class EntitySearchProviderTest extends TestCase
{
    private NoteDatabase $db;

    private string $marker;

    protected function setUp(): void
    {
        $this->db     = NoteDatabase::get();
        $this->marker = 'skp' . bin2hex(random_bytes(6));
    }

    public function testArchivedAndHiddenRowsAreNeitherShownNorCounted(): void
    {
        $this->db->insert('tenant-a', $this->marker . ' visible');
        $this->db->insert('tenant-a', $this->marker . ' archived', true);
        $this->db->insert('tenant-a', $this->marker . ' hidden', false, true);
        $viewer = new Viewer('tenant-a', 'alice');

        $group = (new EntitySearchProvider(new DbalNoteDefinition($this->db->connection, $viewer)))->search($this->query($this->marker));

        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertSame(['notes', 'Notes', 1, '/notes?q=' . $this->marker, 'See all', true], [$group->key, $group->label, $group->total, $group->moreUrl, $group->moreLabel, $group->exactTotal]);
        self::assertSame([$this->marker . ' visible'], $this->titles($group));
        self::assertSame('tabler:note', $group->items[0]->icon, 'The definition icon fills a row without one.');
    }

    public function testEveryWordMustMatchCaseInsensitivelyInAnyField(): void
    {
        $this->db->insert('tenant-a', $this->marker . ' Alpha', false, false, 'alice', 'gamma');
        $this->db->insert('tenant-a', $this->marker . ' alpha beta');
        $viewer   = new Viewer('tenant-a', 'alice');
        $provider = new EntitySearchProvider(new DbalNoteDefinition($this->db->connection, $viewer));

        self::assertSame([$this->marker . ' Alpha', $this->marker . ' alpha beta'], $this->titles($provider->search($this->query('ALPHA ' . $this->marker))));
        self::assertSame([$this->marker . ' Alpha'], $this->titles($provider->search($this->query($this->marker . ' GAMMA alpha'))));
        self::assertNull($provider->search($this->query($this->marker . ' delta')));
    }

    public function testScopeIsAppliedInSqlOrRefused(): void
    {
        $this->db->insert('tenant-a', $this->marker . ' mine', false, false, 'alice');
        $this->db->insert('tenant-a', $this->marker . ' theirs', false, false, 'bob');
        $viewer   = new Viewer('tenant-a', 'alice');
        $provider = new EntitySearchProvider(new DbalNoteDefinition($this->db->connection, $viewer));

        self::assertSame([$this->marker . ' theirs'], $this->titles($provider->search($this->query($this->marker, 'owner:bob'))));
        self::assertNull($provider->search($this->query($this->marker, 'owner:carol')));
        self::assertNull($provider->search($this->query($this->marker, 'project:1')));
        self::assertNull($provider->search($this->query($this->marker, 'owner bob')));
    }

    public function testCutWindowGivesAnInexactVisibleTotal(): void
    {
        for ($row = 0; $row < 4; ++$row) {
            $this->db->insert('tenant-a', $this->marker . ' ' . $row);
        }
        $definition      = new StubDefinition();
        $definition->max = 3;

        $group = (new EntitySearchProvider($definition))->search($this->query($this->marker, null, 2));

        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertCount(2, $group->items);
        self::assertSame(3, $group->total);
        self::assertFalse($group->exactTotal);
    }

    public function testRefusals(): void
    {
        $this->db->insert('tenant-a', $this->marker);
        $definition = new StubDefinition();
        $provider   = new EntitySearchProvider($definition);
        self::assertInstanceOf(SearchGroup::class, $provider->search($this->query($this->marker)));

        self::assertNull($provider->search($this->query('   ')), 'Empty text.');
        self::assertNull($provider->searchPage($this->query($this->marker), 2, 100), 'Page past the candidates.');
        $definition->min = 50;
        self::assertNull($provider->search($this->query($this->marker)), 'Shorter than minLength().');
        $definition->min    = null;
        $definition->tenant = null;
        self::assertNull($provider->search($this->query($this->marker)), 'No tenant.');
        $definition->tenant  = 'tenant-a';
        $definition->feature = false;
        self::assertNull($provider->search($this->query($this->marker)), 'Feature off.');
        $definition->feature = true;
        self::assertNull($provider->search($this->query($this->marker, 'any:scope')), 'Default: no scopes.');
        self::assertNull($provider->search($this->query($this->marker . 'zz')), 'No match.');
        self::assertNull((new EntitySearchProvider($definition, null, null, new SearchGroupSettings(['stub' => ['enabled' => false]])))->search($this->query($this->marker)), 'Group off.');
        $definition->visible = static fn (array $rows): array => [];
        self::assertNull($provider->search($this->query($this->marker)), 'Nothing visible.');
    }

    public function testDefaultCanViewDeniesEverything(): void
    {
        $this->db->insert('tenant-a', $this->marker);
        $definition          = new StubDefinition();
        $definition->canView = false;

        self::assertNull((new EntitySearchProvider($definition))->search($this->query($this->marker)));
    }

    public function testRowWithoutAnIdNeedsItsOwnSubjectId(): void
    {
        $this->db->insert('tenant-a', $this->marker);
        $definition        = new StubDefinition();
        $definition->query = fn (int|string $tenant): DbalQueryBuilder => $this->db->connection->createQueryBuilder()
            ->select('n.title')->from(NoteDatabase::TABLE, 'n')->where('n.tenant = :tenant')->setParameter('tenant', $tenant);

        $this->expectException(LogicException::class);
        (new EntitySearchProvider($definition))->search($this->query($this->marker));
    }

    public function testRowsTheBundleDidNotReturnAsCandidatesAreIgnored(): void
    {
        $this->db->insert('tenant-a', $this->marker);
        $definition          = new StubDefinition();
        $definition->visible = static fn (array $rows): array => [...$rows, ['id' => 999999999, 'title' => 'forged']]; // @phpstan-ignore assign.propertyType

        self::assertSame([$this->marker], $this->titles((new EntitySearchProvider($definition))->search($this->query($this->marker))));
    }

    public function testSensitiveGroupIsOffWhileRecentsKeepTitles(): void
    {
        $this->db->insert('tenant-a', $this->marker);
        $definition            = new StubDefinition();
        $definition->sensitive = true;
        $logger                = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $notice = new SensitiveGroupNotice($logger);
        self::assertNull((new EntitySearchProvider($definition, null, null, new SearchGroupSettings(), false, $notice))->search($this->query($this->marker)));
        self::assertNull((new EntitySearchProvider($definition, null, null, new SearchGroupSettings(), false, $notice))->search($this->query($this->marker)));
        $definition->group = 'stub_two';
        self::assertNull((new EntitySearchProvider($definition, null, null, new SearchGroupSettings(), false, $notice))->search($this->query($this->marker)));
        self::assertCount(2, $logger->messages, 'Once per group and container.');
        (new SensitiveGroupNotice())->report('silent');
        self::assertStringNotContainsString($this->marker, $logger->messages[0]);
        self::assertNull((new EntitySearchProvider($definition, null, null, new SearchGroupSettings(), false))->search($this->query($this->marker)));
        self::assertInstanceOf(SearchGroup::class, (new EntitySearchProvider($definition))->search($this->query($this->marker)), 'Recents revalidated or off.');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidGroups(): iterable
    {
        yield 'upper case and space' => ['Not Valid'];
        yield 'digit first' => ['1tasks'];
        yield 'trailing newline' => ["tasks\n"];
    }

    #[DataProvider('invalidGroups')]
    public function testInvalidGroupKeyThrows(string $key): void
    {
        $definition        = new StubDefinition();
        $definition->group = $key;
        $provider          = new EntitySearchProvider($definition);
        self::assertSame($key, $provider->groupKey());

        $this->expectException(InvalidArgumentException::class);
        $provider->search($this->query('anything'));
    }

    public function testFullTextHookReplacesLike(): void
    {
        $this->db->insert('tenant-a', $this->marker . ' full');
        $definition           = new StubDefinition();
        $seen                 = [];
        $definition->fullText = static function (DbalQueryBuilder|OrmQueryBuilder $queryBuilder, array $terms) use (&$seen): bool {
            $seen = $terms;
            $queryBuilder->andWhere('n.title LIKE :ft')->setParameter('ft', $terms[0] . '%');

            return true;
        };

        $group = (new EntitySearchProvider($definition))->search($this->query($this->marker . '* "-x'));

        self::assertSame([$this->marker, 'x'], $seen);
        self::assertSame([$this->marker . ' full'], $this->titles($group));
    }

    public function testOrmQueryMustReturnAList(): void
    {
        $this->db->insert('tenant-a', $this->marker . ' a');
        $this->db->insert('tenant-a', $this->marker . ' b');
        $definition        = new StubDefinition();
        $definition->query = fn (int|string $tenant): OrmQueryBuilder => $this->db->entityManager()->createQueryBuilder()
            ->select('n')->from(Note::class, 'n', 'n.id')->where('n.tenant = :tenant')->setParameter('tenant', $tenant);

        $this->expectException(LogicException::class);
        (new EntitySearchProvider($definition))->search($this->query($this->marker));
    }

    public function testReservedParametersAreRefusedForDbalAndOrm(): void
    {
        $definition        = new StubDefinition();
        $definition->query = fn (int|string $tenant): DbalQueryBuilder => $this->db->connection->createQueryBuilder()
            ->select('n.id', 'n.title')->from(NoteDatabase::TABLE, 'n')->where('n.owner = :nowo_search_w0')->setParameter('nowo_search_w0', 'alice');
        try {
            (new EntitySearchProvider($definition))->search($this->query('anything'));
            self::fail('DBAL: a reserved parameter must be refused.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $definition->query = fn (int|string $tenant): OrmQueryBuilder => $this->db->entityManager()->createQueryBuilder()
            ->select('n')->from(Note::class, 'n')->where('n.owner = :nowo_search_id')->setParameter('nowo_search_id', 'alice');
        $definition->useIdField     = true;
        $definition->idField        = 'n.id';
        $definition->useReferenceId = true;

        $this->expectException(LogicException::class);
        (new EntitySearchProvider($definition))->resolve(new SearchReference('/stub/1', 'stub', 'stub_show', ['id' => '1']), 'alice', null);
    }

    public function testHostEventGetsOneBatchAndDenyWins(): void
    {
        $this->db->insert('tenant-a', $this->marker . ' one');
        $this->db->insert('tenant-a', $this->marker . ' two');
        $this->db->insert('tenant-a', $this->marker . ' three', false, true);
        $events     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SearchKitEvents::ITEM_VISIBILITY, static function (SearchItemVisibilityEvent $event) use (&$events): void {
            $events[] = $event;
            $event->deny($event->subjects()[1]);
        });
        $dispatcher->addListener(SearchKitEvents::ITEM_VISIBILITY, static function (SearchItemVisibilityEvent $event): void {
            foreach ($event->subjects() as $subject) {
                $event->allow($subject);
            }
        });
        $viewer = new Viewer('tenant-a', 'alice');

        $group = (new EntitySearchProvider(new DbalNoteDefinition($this->db->connection, $viewer), $dispatcher))->search($this->query($this->marker, 'owner:alice'));

        self::assertSame([$this->marker . ' one'], $this->titles($group));
        self::assertSame(1, $group?->total);
        self::assertCount(1, $events);
        self::assertSame(['notes', 'alice', 'tenant-a', 'owner:alice', 'en'], [$events[0]->group, $events[0]->userIdentifier, $events[0]->tenant, $events[0]->scope, $events[0]->locale]);
        self::assertCount(2, $events[0]->subjects(), 'The bundle check runs first: the hidden row is not a candidate.');
        self::assertSame('notes', $events[0]->subjects()[0]->type);
    }

    public function testTranslatedSeeAllLabelAndOwnIcon(): void
    {
        $viewer = new Viewer(7, 'alice');
        $this->db->insert('7', 'Note', false, false, 'alice', $this->marker);

        $group = (new EntitySearchProvider(new OrmNoteDefinition($this->db->entityManager(), $viewer), null, new IdentityTranslator()))->search($this->query($this->marker));

        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertSame(['search.see_all', null, 'tabler:file-text', 'note'], [$group->moreLabel, $group->moreUrl, $group->items[0]->icon, $group->items[0]->subtitle]);
    }

    public function testRowsWithoutAnyIcon(): void
    {
        $this->db->insert('tenant-a', $this->marker);

        $group = (new EntitySearchProvider(new StubDefinition()))->search($this->query($this->marker));

        self::assertNull($group?->items[0]->icon);
    }

    public function testRecentsAreResolvedThroughEveryCheck(): void
    {
        $id      = $this->db->insert('tenant-a', $this->marker . ' kept');
        $hidden  = $this->db->insert('tenant-a', $this->marker . ' hidden', false, true);
        $other   = $this->db->insert('tenant-b', $this->marker . ' other');
        $viewer  = new Viewer('tenant-a', 'alice');
        $notes   = new DbalNoteDefinition($this->db->connection, $viewer);
        $resolve = static fn (int $note, string $kind = 'notes', ?string $url = null, string $user = 'alice', ?SearchGroupSettings $groups = null): ?SearchItem => (new EntitySearchProvider($notes, null, null, $groups ?? new SearchGroupSettings()))
            ->resolve(new SearchReference($url ?? '/notes/' . $note, $kind, 'note_show', ['id' => (string) $note]), $user, 'en');

        self::assertSame($this->marker . ' kept', $resolve($id)?->title);
        self::assertNull($resolve($hidden), 'Not visible.');
        self::assertNull($resolve($other), 'Other tenant.');
        self::assertNull($resolve($id, 'pages'), 'Other kind.');
        self::assertNull($resolve($id, 'notes', '/notes/' . $id . '?x=1'), 'Other URL.');
        self::assertNull($resolve($id, 'notes', null, 'bob'), 'No tenant.');
        self::assertNull($resolve($id, 'notes', null, 'alice', new SearchGroupSettings(['notes' => ['enabled' => false]])), 'Group off.');
        $viewer->feature = false;
        self::assertNull($resolve($id), 'Feature off.');
        $viewer->feature = true;
        self::assertNull((new EntitySearchProvider($notes))->resolve(new SearchReference('/notes/' . $id, 'notes', 'other_route', ['id' => (string) $id]), 'alice', null), 'Not a reference of the group.');
    }

    public function testRecentsNeedAValidIdField(): void
    {
        $id         = $this->db->insert('tenant-a', $this->marker);
        $definition = new StubDefinition();
        $provider   = new EntitySearchProvider($definition);
        $reference  = new SearchReference('/stub/' . $id, 'stub', 'stub_show', ['id' => (string) $id]);

        self::assertNull($provider->resolve($reference, 'alice', null), 'Default: no id field.');
        $definition->useIdField = true;
        $definition->idField    = 'n.id; DROP TABLE x';
        self::assertNull($provider->resolve($reference, 'alice', null), 'Unsafe id field.');
        $definition->idField = 'n.id';
        self::assertNull($provider->resolve($reference, 'alice', null), 'Default: no reference id.');
        $definition->useReferenceId = true;
        $item                       = (new EntitySearchProvider($definition))->resolve($reference, 'alice', null);
        self::assertSame('/stub/' . $id, $item?->url);
    }

    private function query(string $text, ?string $scope = null, int $limit = 10): SearchQuery
    {
        return new SearchQuery($text, null, $limit, $scope, false, 'alice', [], 'en');
    }

    /**
     * @return list<string>
     */
    private function titles(?SearchGroup $group): array
    {
        return $group instanceof SearchGroup ? array_map(static fn (SearchItem $item): string => $item->title, $group->items) : [];
    }
}
