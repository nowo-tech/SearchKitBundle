# Write a provider

Since **1.9.0** a bundle (or an application) puts its records in the search palette and the results page by writing one
`EntitySearchDefinition`: a handful of hooks that say *what* to search. *How* it is searched — tenant, feature gate,
scope, escaped LIKE, bounded limit, access checks, the shared host event, the visible total, the "see all" link and
`null` on any refusal — is SearchKit's `EntitySearchProvider`, the same for every bundle.

## Table of contents

- [The example](#the-example)
- [Registering it](#registering-it)
- [The flow](#the-flow)
- [Hooks](#hooks)
  - [Required](#required)
  - [Optional](#optional)
- [ORM definitions](#orm-definitions)
- [Scopes](#scopes)
- [Full text](#full-text)
- [Recents](#recents)
- [The host visibility event](#the-host-visibility-event)
- [Group switches](#group-switches)
- [Helpers](#helpers)
- [Testing a provider: the contract kit](#testing-a-provider-the-contract-kit)
- [Rules](#rules)

## The example

A DBAL definition of tasks. The tenant comes from the bundle's own resolver, `view` from its own access checker (which
dispatches the bundle's own access event, so host rules still apply), and the batch check runs one query per page:

```php
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Nowo\SearchKitBundle\Model\{SearchItem, SearchQuery};
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Search\Query\SeeAllLink;

final class TaskSearchDefinition extends EntitySearchDefinition
{
    public function __construct(private Connection $db, private TenantResolver $tenants, private TaskAccessChecker $access, private UrlGeneratorInterface $urls, private TranslatorInterface $translator) {}

    public function group(): string { return 'tasks'; }
    public function label(SearchQuery $query): string { return $this->translator->trans('search.group', [], 'AcmeTasks', $query->locale); }
    public function icon(): ?string { return 'tabler:checkbox'; }
    public function tenant(SearchQuery $query): ?int { return $this->tenants->current()?->id(); }
    public function featureEnabled(int|string $tenant, SearchQuery $query): bool { return $this->access->featureEnabled($tenant); }
    public function searchableFields(): array { return ['t.title', 't.reference']; }

    public function baseQuery(int|string $tenant, SearchQuery $query): QueryBuilder
    {
        return $this->db->createQueryBuilder()->select('t.id', 't.title', 't.board_id')->from('task', 't')
            ->where('t.tenant_id = :tenant AND t.archived_at IS NULL AND t.deleted_at IS NULL')
            ->setParameter('tenant', $tenant)->orderBy('t.updated_at', 'DESC')->addOrderBy('t.id', 'DESC');
    }

    public function filterVisible(array $rows, int|string $tenant, SearchQuery $query): array
    {
        return $this->access->visibleRows($rows, $query->userIdentifier); // one query for the whole page
    }

    public function toItem(array|object $row, SearchQuery $query): SearchItem
    {
        return new SearchItem($row['title'], $this->urls->generate('acme_task_show', ['id' => $row['id']]));
    }

    public function seeAllUrl(SearchQuery $query): ?string { return SeeAllLink::build($this->urls, 'acme_task_list', [], $query); }
}
```

## Registering it

With autoconfiguration, a service extending `EntitySearchDefinition` gets the `nowo_search_kit.entity_definition` tag
and SearchKit registers one `EntitySearchProvider` for it (service id `nowo_search_kit.entity_provider.<definition id>`,
tagged as a provider and as a recents resolver). Without autoconfiguration, add the tag yourself; its optional
`priority` attribute (default 0) places the group among the providers:

```php
// A bundle extension: wire the definition only when SearchKit is installed (it stays a suggest / require-dev).
if (class_exists(EntitySearchDefinition::class) && $config['search_kit']['enabled']) {
    $container->register(TaskSearchDefinition::class)->setAutowired(true)
        ->addTag(EntitySearchDefinition::TAG, ['priority' => 40]);
}
```

The same class may be registered twice with different arguments (e.g. one definition per record type): each service is
its own group.

## The flow

Per call, always in this order:

1. the group is on (`nowo_search_kit.groups.<key>.enabled`); a [sensitive](#optional) group only runs while recents are
   revalidated or off;
2. a non-empty text of at least `minLength()` characters, a results page inside the candidate window;
3. `tenant()` — `null` means no results — then `featureEnabled()`;
4. the scope (client input): shape-checked, then `applyScope()` restricts the query in SQL — or the search is refused;
5. `applyFullText()`, or an escaped `LOWER(field) LIKE :p ESCAPE '!'` of every query word over `searchableFields()`
   (each word must occur in one of the fields);
6. one query of at most `maxCandidates() + 1` rows;
7. `filterVisible()` on the candidates (batched; SearchKit keeps only rows that were candidates), then **one**
   `SearchKitEvents::ITEM_VISIBILITY` event for the page, where a host deny wins;
8. the page cut out of the visible rows, the visible total (exact only when the candidates were not cut), `toItem()`
   on the shown rows only, `seeAllUrl()`.

**Any refusal is `null`**: no group, no count, no hint, so a forbidden or hidden row looks exactly like a missing one.
An exception thrown by a hook reaches SearchKit, which logs the class names (never the query text) and drops the group.

The provider is a `PaginatedSearchProviderInterface`: the results page pages the group through the same flow, at most
`maxCandidates()` rows deep.

## Hooks

### Required

| Hook | Returns |
|------|---------|
| `group()` | Group key, `[a-z0-9_-]{1,64}`: the palette section, the `type:` filter value, the `groups.<key>` settings, the default row kind. |
| `label(SearchQuery $query)` | The translated group label (use `$query->locale`). |
| `tenant(SearchQuery $query)` | The viewer's tenant (`int\|string`), resolved on each call; `null`: no results. `$query->userIdentifier` is the viewer. |
| `featureEnabled(int\|string $tenant, SearchQuery $query)` | Whether the feature (and, per tenant, `view`) is on. |
| `searchableFields()` | `column` or `alias.column` names searched with LIKE. Never secret values, notes or bodies of sensitive records. |
| `baseQuery(int\|string $tenant, SearchQuery $query)` | A **new** DBAL or ORM query builder: select, from, the tenant condition and the SQL filters of visibility, archived and deleted rows, ordered deterministically. Set no limit; bind no parameter named `nowo_search_*`. |
| `toItem(array\|object $row, SearchQuery $query)` | The `SearchItem` of a visible row (router-generated URL; SearchKit escapes what it shows). |
| `seeAllUrl(SearchQuery $query)` | The bundle's list page with the query (`SeeAllLink::build()`), or `null`. |

And one of:

| Hook | Default |
|------|---------|
| `canView(array\|object $row, int\|string $tenant, SearchQuery $query)` | `false` — deny. Per-row check through the bundle's own checker or access event. |
| `filterVisible(array $rows, int\|string $tenant, SearchQuery $query)` | `canView()` per row. Override it to check the whole page at once (no N+1). |

### Optional

| Hook | Default | Use |
|------|---------|-----|
| `icon()` | `null` | UX Icons name of rows whose `toItem()` sets none. |
| `subjectType()` | the group key | Subject type in the host event (`[a-z][a-z0-9_.-]{0,63}`). |
| `subjectId(array\|object $row)` | `$row['id']` or `$row->getId()` | The row id (host event, candidate check). |
| `applyScope($queryBuilder, string $scope, int\|string $tenant, SearchQuery $query)` | `false` (no scopes) | See [Scopes](#scopes). |
| `applyFullText($queryBuilder, array $terms, SearchQuery $query)` | `false` (LIKE) | See [Full text](#full-text). |
| `minLength()` | `0` | Shortest text this group searches (SearchKit's `min_length` applies first). |
| `maxCandidates()` | `100` | Rows read and checked per search (1–1000): bounds the checks, the total and the deepest page. |
| `sensitive()` | `false` | `true` when titles are sensitive (message excerpts, private names): the group is off while recents keep titles in the browser. |
| `idField()` / `referenceId(SearchReference $reference)` | `null` | See [Recents](#recents). |

## ORM definitions

`baseQuery()` may return a `Doctrine\ORM\QueryBuilder`; the fields are then DQL paths (`t.title`) and the rows are
what the query returns (entities). Do not fetch-join collections (the limit applies to SQL rows), and do not index the
result (`INDEX BY`): SearchKit needs a list.

```php
public function baseQuery(int|string $tenant, SearchQuery $query): \Doctrine\ORM\QueryBuilder
{
    return $this->em->createQueryBuilder()->select('t')->from(Task::class, 't')
        ->where('t.tenant = :tenant')->andWhere('t.archivedAt IS NULL')
        ->setParameter('tenant', $tenant)->orderBy('t.id', 'DESC');
}

public function canView(array|object $row, int|string $tenant, SearchQuery $query): bool
{
    return $row instanceof Task && $this->access->canView($row, $query->userIdentifier);
}
```

## Scopes

The scope comes from the browser: **client input**. SearchKit refuses a malformed one (`SearchScope::ID_PATTERN`) before
your hook runs; `applyScope()` must still check, **in SQL**, that the scoped object belongs to the tenant and is visible,
and return `false` for a scope it does not handle (the search is then `null`, like a scope with no match):

```php
public function applyScope(DbalQueryBuilder|OrmQueryBuilder $qb, string $scope, int|string $tenant, SearchQuery $query): bool
{
    $board = ScopeParser::positiveInt($scope, 'board');
    if ($board === null) {
        return false;
    }
    $qb->andWhere('t.board_id = :board')->setParameter('board', $board); // the tenant condition is already there

    return true;
}
```

## Full text

`applyFullText()` gets `SearchWords::fullTextTerms()` of the query: lower-case runs of letters and digits only (no
operator, quote, wildcard or punctuation survives), at most 8. Add your own condition and return `true`; return
`false` — also for an empty list — to fall back to the escaped LIKE.

## Recents

With `recent.revalidate: true` the browser keeps only references (URL + kind) and SearchKit asks for the rows again on
every display. The generated provider resolves the references of its group when the definition gives:

- `idField()`: the id field of `baseQuery()` (`t.id`);
- `referenceId(SearchReference $reference)`: the record id from the stored route parameters, or `null` when the
  reference is not one of the group's rows (check `$reference->route`).

The row is then read through `baseQuery()` and every check above, and kept only when `toItem()` gives the same URL and
kind. A definition without these hooks has its recents dropped under revalidation.

A `sensitive()` group is off while SearchKit keeps recents in the browser without revalidation
(`recent.enabled: true`, `recent.revalidate: false`); SearchKit logs why (no query text).

## The host visibility event

After the bundle's own check, every provider dispatches **one** `SearchItemVisibilityEvent`
(`SearchKitEvents::ITEM_VISIBILITY`, `nowo_search_kit.item_visibility`) for the page, with every remaining candidate.
A host applies its cross-cutting rules there, once for every bundle:

```php
#[AsEventListener(SearchKitEvents::ITEM_VISIBILITY)]
final class SearchVisibilityListener
{
    public function __invoke(SearchItemVisibilityEvent $event): void
    {
        if ($event->group === 'tasks' && !$this->features->isOn($event->tenant, 'tasks')) {
            $event->denyAll();

            return;
        }
        foreach ($event->subjects() as $subject) { // SearchSubject: type, id
            if ($this->legalHold->hides($subject->type, $subject->id, $event->userIdentifier)) {
                $event->deny($subject);
            }
        }
    }
}
```

| Member | Meaning |
|--------|---------|
| `group`, `userIdentifier`, `tenant`, `scope`, `locale` | Read-only context (never the query text). |
| `subjects(): list<SearchSubject>` | The candidates, in search order. |
| `allow(SearchSubject)` | Recorded; never undoes a deny. |
| `deny(SearchSubject)` / `denyAll()` | The row is dropped before it is counted. **Deny wins**, whatever the listener order. |
| `decision(SearchSubject): ?bool` / `isDenied(SearchSubject)` | `true` allowed, `false` denied, `null` default (the bundle's decision stands: shown). |

Subjects a listener names that are not candidates are ignored. Without an event dispatcher no event is dispatched.

## Group switches

`nowo_search_kit.groups.<key>` applies to every provider whose group key SearchKit knows, not only to entity
providers (see [CONFIGURATION](CONFIGURATION.md#groups-190)):

```yaml
nowo_search_kit:
    groups:
        tasks: { priority: 60 }
        crm_contacts: { enabled: false }
```

## Helpers

All stateless, in `Nowo\SearchKitBundle\Search\Query`:

| Helper | Does |
|--------|------|
| `LikePattern::escape()` / `contains()` | Escapes `!`, `%`, `_` with `!` (`ESCAPE '!'`); a backslash stays literal. |
| `LikePattern::condition()` / `anyField()` / `isField()` | `LOWER(field) LIKE :p ESCAPE '!'` (DBAL SQL and DQL); field names are checked. |
| `SearchWords::words()` / `fullTextTerms()` | The bounded words of a query (8 words, 64 characters each). |
| `ScopeParser::isValid()` / `value()` / `positiveInt()` | Reads `prefix:value` scopes; `null` for anything else. |
| `SearchWindow::of()` | The bounded candidate window, the page slice and the visible total. |
| `SeeAllLink::build()` | The list-page link with the query text (and optionally the scope), `null` when unsafe. |

## Testing a provider: the contract kit

`Nowo\SearchKitBundle\Test\EntitySearchProviderTestCase` (PHPUnit) asserts the contract against your real definition
and database: refusals give `null` (no tenant, feature off, malformed and unknown scopes, a host deny), no other-tenant
row, literal wildcards (`%`, `_`, `!`, `\`), the limit and the visible total, a query budget, and worker safety (one
provider instance, two viewers alternately). Run it on MySQL, MariaDB and PostgreSQL in CI.

```php
use Doctrine\DBAL\{Configuration, DriverManager};
use Doctrine\DBAL\Tools\DsnParser;
use Nowo\SearchKitBundle\Test\{EntitySearchProviderTestCase, QueryCounter, QueryCountingMiddleware};

final class TaskSearchContractTest extends EntitySearchProviderTestCase
{
    private QueryCounter $counter;
    private Connection $db;
    private FakeTenantResolver $tenants;   // what your definition reads on every call
    private FakeTaskAccess $access;

    protected function setUp(): void
    {
        $this->counter = new QueryCounter();
        $config = new Configuration();
        $config->setMiddlewares([new QueryCountingMiddleware($this->counter)]);
        $this->db = DriverManager::getConnection((new DsnParser())->parse((string) getenv('DATABASE_URL')), $config); // schema created once
        $this->tenants = new FakeTenantResolver();
        $this->access = new FakeTaskAccess($this->db);
    }

    protected function definition(): EntitySearchDefinition { return new TaskSearchDefinition($this->db, $this->tenants, $this->access, $this->urls(), $this->translator()); }
    protected function queryCounter(): QueryCounter { return $this->counter; }
    protected function tenants(): array { return [1, 2]; }
    protected function seed(int|string $tenant, string $text): void { $this->db->insert('task', ['tenant_id' => $tenant, 'title' => $text]); }
    protected function actAs(int|string|null $tenant, string $userIdentifier): void { $this->tenants->set($tenant, $userIdentifier); $this->access->enable(); }
    protected function disableFeature(): void { $this->access->disable(); }
    protected function queryBudget(): int { return 2; } // the search query + one batched access check
}
```

| Hook | You provide |
|------|-------------|
| `definition()` | The definition, wired to the test database. |
| `queryCounter()` | The counter of the test connection's `QueryCountingMiddleware` (also usable on an EntityManager's connection). |
| `tenants()` | Two different tenants. |
| `seed($tenant, $text)` | One row of the tenant, visible to its viewers, whose searchable text contains `$text` (it may contain `%`, `_`, `!`, `\`). |
| `actAs($tenant, $userIdentifier)` | Makes the definition resolve this viewer and tenant (null: none), feature on. |
| `disableFeature()` | Turns the feature (or `view`) off for the current viewer. |
| `queryBudget()` | Most statements per search (default 2). |

Each test seeds rows under a fresh random marker, so the tests need no cleanup and never see each other's rows. Add
your own tests next to the kit's (archived rows, your access event, scopes).

## Rules

- **Stateless**: FrankenPHP worker mode with kernel reset off. Resolve the tenant and the viewer on each call; keep
  nothing in properties between calls.
- **Filter in SQL first** (tenant, visibility, archived and deleted rows), then check each candidate (batched).
- **Oracle-free**: never return a count, an error or a hint for a refusal; SearchKit returns `null`.
- **Escape**: SearchKit escapes titles and subtitles; never put secret values, notes or bodies of sensitive records in
  them, and set `sensitive()` when titles are private.
- **Optional dependency**: SearchKit stays in `suggest` and `require-dev`; wire the definition only when it is
  installed.
