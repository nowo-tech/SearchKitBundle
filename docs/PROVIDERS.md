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
- [Display data for the shown rows: prepareItems()](#display-data-for-the-shown-rows-prepareitems)
- [Rows hidden only in PHP still use the candidate window](#rows-hidden-only-in-php-still-use-the-candidate-window)
- [Bounded candidates: a partial total](#bounded-candidates-a-partial-total)
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
3. `tenant()` — `null` means no results — then `featureEnabled()`; then `candidateQuery()` (1.11.0; default
   `baseQuery()`) with a new `SearchCandidateContext` for this search;
4. the scope (client input): shape-checked, then `applyScope()` restricts the query in SQL — or the search is refused;
5. `applyFullText()`, or an escaped `LOWER(field) LIKE :p ESCAPE '!'` of every query word over `searchableFields()`
   (each word must occur in one of the fields);
6. one query of at most `maxCandidates()` rows (a parameter named `nowo_search_*` bound by `baseQuery()`,
   `applyScope()` or `applyFullText()` is refused with a `LogicException`: SearchKit binds those);
7. `filterCandidates()` (1.11.0; default `filterVisible()`) on the candidates, with the same context (batched;
   SearchKit keeps only rows that were candidates), then **one**
   `SearchKitEvents::ITEM_VISIBILITY` event for the page, where a host deny wins;
8. the page cut out of the visible rows, the visible total within the window, `seeAllUrl()`, then the shown rows
   rendered: **one** `prepareItems()` call with exactly those rows (1.10.0), and `toPreparedItem()` — by default
   `toItem()` — per shown row only. Rows the viewer may not see are never counted. The total is exact only when the
   SQL fetch did **not** fill the window (1.10.1): once `maxCandidates()` rows came back, visible or not, it is a lower
   bound and SearchKit shows the rows it lists. It is not exact either when the definition marked its candidates
   partial for this search (1.11.0, [Bounded candidates](#bounded-candidates-a-partial-total)). Hidden rows **inside** the window still take places, so they can lower
   the visible count and the rows reachable by paging — see
   [Rows hidden only in PHP](#rows-hidden-only-in-php-still-use-the-candidate-window). **Filter per-row visibility in
   SQL** (`baseQuery()`) wherever possible, and keep `filterVisible()` for what SQL cannot express.

**Any refusal is `null`**: no group, no count, no hint, so a forbidden or hidden row looks exactly like a missing one.
An exception thrown by a hook reaches SearchKit, which logs the class names (never the query text) and drops the group.

The provider is a `PaginatedSearchProviderInterface`: the results page pages the group through the same flow, at most
`maxCandidates()` rows deep.

## Hooks

### Required

| Hook | Returns |
|------|---------|
| `group()` | Group key, a lower-case letter then up to 63 of `a-z`, `0-9`, `_`, `-` (`EntitySearchProvider::GROUP_PATTERN`): the palette section, the `type:` filter value, the `groups.<key>` settings, the default row kind. |
| `label(SearchQuery $query)` | The translated group label (use `$query->locale`). |
| `tenant(SearchQuery $query)` | The viewer's tenant (`int\|string`), resolved on each call; `null`: no results. `$query->userIdentifier` is the viewer. |
| `featureEnabled(int\|string $tenant, SearchQuery $query)` | Whether the feature (and, per tenant, `view`) is on. |
| `searchableFields()` | `column` or `alias.column` names searched with LIKE. Never secret values, notes or bodies of sensitive records. |
| `baseQuery(int\|string $tenant, SearchQuery $query)` | A **new** DBAL or ORM query builder: select, from, the tenant condition and the SQL filters of visibility, archived and deleted rows, ordered deterministically. Set no limit; bind no parameter named `nowo_search_*`. |
| `toItem(array\|object $row, SearchQuery $query)` | The `SearchItem` of a visible row (router-generated URL; SearchKit escapes what it shows). Since 1.10.0 you may override `toPreparedItem()` instead (see [prepareItems()](#display-data-for-the-shown-rows-prepareitems)). |
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
| `sensitive()` | `false` | `true` when titles are sensitive (message excerpts, private names): the group is off while recents keep titles in the browser (SearchKit logs why once per container). |
| `idField()` / `referenceId(SearchReference $reference)` | `null` | See [Recents](#recents). |
| `prepareItems(array $shownRows, SearchQuery $query, SearchItemContext $context)` | nothing | 1.10.0. Batch-load display data for the shown rows only. See [prepareItems()](#display-data-for-the-shown-rows-prepareitems). |
| `toPreparedItem(array\|object $row, SearchQuery $query, SearchItemContext $context)` | `toItem()` | 1.10.0. The row, with what `prepareItems()` stored. |
| `candidateQuery(int\|string $tenant, SearchQuery $query, SearchCandidateContext $context)` | `baseQuery()` | 1.11.0. The query SearchKit runs; call `$context->markPartial()` when your own bound is hit. See [Bounded candidates](#bounded-candidates-a-partial-total). |
| `filterCandidates(array $rows, int\|string $tenant, SearchQuery $query, SearchCandidateContext $context)` | `filterVisible()` | 1.11.0. The batch check SearchKit runs, with the same context. |

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
  reference is not one of the group's rows. The reference is **client input**: check the route and the shape of the
  id, and return `null` for anything else:

```php
public function idField(): ?string { return 't.id'; }

public function referenceId(SearchReference $reference): ?string
{
    $id = $reference->routeParameters['id'] ?? null;

    return $reference->route === 'acme_task_show' && is_string($id) && ctype_digit($id) ? $id : null; // a UUID key: match its pattern
}
```

The row is then read through `baseQuery()` and every check above, and kept only when `toItem()` gives the same URL and
kind. A definition without these hooks has its recents dropped under revalidation.

A `sensitive()` group is off while SearchKit keeps recents in the browser without revalidation
(`recent.enabled: true`, `recent.revalidate: false`); SearchKit logs why once per group and container (no query
text).

## Display data for the shown rows: prepareItems()

Since **1.10.0**. Some display data is not in the search query: a title that a host resolver gives, a name from another
table. Loading it in `toItem()` costs one query per row (N+1), and loading it in `filterVisible()` loads it for rows the
host event may still deny. `prepareItems()` runs at the right moment:

- **once** per search page, with exactly the rows the provider returns — after `filterVisible()`, after the host's
  `ITEM_VISIBILITY` event and after the page cut — right before they are rendered;
- once per resolved recent (revalidation), with that one row;
- **never** when the provider returns no row (the search is `null`), nor for a group the viewer's `type:` filter
  excludes (SearchKit does not call that provider at all).

So nothing is loaded for a row the bundle's check or the host event hides, and one batched query serves the page.
With `access.filter_all_rows` (see [CONFIGURATION](CONFIGURATION.md)), SearchKit's engine then checks the URL of every
returned row and may still drop some: data prepared for those rows is simply not shown. Store what you load
in the `SearchItemContext` SearchKit passes, and read it back in `toPreparedItem()`:

```php
use Nowo\SearchKitBundle\Provider\Entity\SearchItemContext;

public function prepareItems(array $shownRows, SearchQuery $query, SearchItemContext $context): void
{
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $shownRows);
    $context->set('titles', $this->titles->forIds($ids, $query->locale)); // one query: id => title
}

public function toPreparedItem(array|object $row, SearchQuery $query, SearchItemContext $context): SearchItem
{
    $title = $context->get('titles', [])[$row['id']] ?? $row['reference'];

    return new SearchItem($title, $this->urls->generate('acme_task_show', ['id' => $row['id']]));
}
```

A definition that overrides `toPreparedItem()` does not need `toItem()` (since 1.10.0 `toItem()` is no longer
abstract: its default throws a `LogicException`, so override one of the two).

Rules:

- **Read-only.** No persist, flush, clear, transaction or any other write (REQ-RUNTIME-003): the search runs inside the
  host's request, on its connection.
- **State in the context, never in a property.** SearchKit creates a new `SearchItemContext` for every page and every
  resolved recent, passes it to `prepareItems()` and to `toPreparedItem()` for those rows, and drops it when the call
  returns. It is never a service and never kept, so data loaded for one viewer cannot reach another request in
  FrankenPHP worker mode with kernel reset off. The definition itself is a shared service: a property set in
  `prepareItems()` would outlive the request.
- **Within the query budget.** Its statements count towards the search's query budget; the contract kit allows one per
  call by default (`prepareItemsBudget()`), and none for the default no-op.

## Rows hidden only in PHP still use the candidate window

SearchKit reads at most `maxCandidates()` rows in the order of `baseQuery()`, and only then runs `filterVisible()` and
the host event. A row those checks drop is never shown or counted, but it has already taken a place in the window.
When the matches exceed the window and hidden rows come first (newer ones, with the usual newest-first order), fewer
visible rows remain: 7 hidden rows ahead of 6 visible ones in a 10-row window leave 3.

That is a side channel. A viewer who can create rows of their own and search for a substring can watch the number of
their own rows that show up shrink, and so learn that rows they cannot see exist and contain that substring. Since
1.10.1 SearchKit never marks such a total exact: a window the fetch filled always gives a lower bound
(`exactTotal` false), which SearchKit caps to the rows it lists. That fixes only the exact claim. The rows that are
pushed out of the window remain missing (3 instead of 6 above), and that cannot be fixed after the fetch.

One binary signal also remains. When the visible rows exceed the page size, the inexact flag tells the viewer whether
the visible and hidden matches together reach `maxCandidates()`. Again, only filtering hidden rows in `baseQuery()`
removes it.

So:

- **Push access rules into `baseQuery()`** wherever SQL can express them (membership joins, owner or visibility
  columns, an `EXISTS` on the grants table). Keep `filterVisible()` for what SQL cannot express.
- **Sensitive groups must** filter in SQL every row the viewer may not see, so that hidden rows never take places in
  the window. In the contract kit, `hiddenRowsFilteredInSql()` defaults to `sensitive()`, so for a sensitive group the
  kit asserts that hidden rows change nothing at all. If only PHP or a host event can decide the visibility, override
  the hook to return `false`, and document in your bundle that the group accepts this side channel.
- A host deny (`ITEM_VISIBILITY`) is a PHP filter too. Use it for rules that span bundles, not as the main access
  check of a large record set.

## Bounded candidates: a partial total

Some definitions bound their own scan before SearchKit's window applies: they read at most N boards (projects,
folders) of the tenant to build the query, or resolve at most N memberships in the access check. When that bound is
hit, the rows SearchKit sees are not all the matches, and a total SearchKit computed from them must not be claimed
exact.

Since 1.11.0 SearchKit runs `candidateQuery()` and `filterCandidates()` instead of calling `baseQuery()` and
`filterVisible()` directly. By default they call those, so a 1.9 or 1.10 definition is unchanged. Both hooks get the
same `SearchCandidateContext`, which is new for every search page and every resolved recent. Call
`$context->markPartial()` when your bound is hit. The group's `exactTotal` is then false, on the palette and the
results page, and SearchKit caps the count to the rows it lists ("N+"). Which rows are shown does not change.

```php
public function baseQuery(int|string $tenant, SearchQuery $query): QueryBuilder
{
    return $this->tasksOf($this->boards->visibleIds($tenant, $query->userIdentifier, self::MAX_BOARDS));
}

public function candidateQuery(int|string $tenant, SearchQuery $query, SearchCandidateContext $context): QueryBuilder
{
    $boards = $this->boards->visibleIds($tenant, $query->userIdentifier, self::MAX_BOARDS + 1);
    if (count($boards) > self::MAX_BOARDS) {
        $context->markPartial();               // more boards than scanned: the total is a lower bound
    }

    return $this->tasksOf(array_slice($boards, 0, self::MAX_BOARDS));
}
```

- **Per search only.** Keep the flag in the context, never in a property of the definition (FrankenPHP worker mode,
  kernel reset off); SearchKit drops the context when the call returns.
- **Keep `baseQuery()` consistent.** It stays abstract, and SearchKit no longer calls it directly once you override
  `candidateQuery()`; build both on one private method.
- **Same rules as `baseQuery()`**: a new builder each call, no limit, no parameter named `nowo_search_*`.
- **Recents.** `resolve()` also calls `candidateQuery()`, with a query whose text is empty. A bound that depends on
  the text (e.g. it counts only the boards that match) therefore treats a recent as unbounded; a bound that leaves the
  recent's row out of the query drops the recent (`null`). Both fail closed: no row the bound did not scan is ever
  shown, and the partial flag has no effect on a single recent.
- **Decorators must forward both hooks.** Any class that wraps another definition — a host decorator (logging,
  caching, extra access rules), not only a test helper — must forward `candidateQuery()` and `filterCandidates()` to
  the inner definition. One that forwards only `baseQuery()` / `filterVisible()` inherits the defaults, which call
  its own `baseQuery()`: the inner definition's bound is skipped and the total is wrongly reported exact.
- **Contract kit**: implement `seedBeyondTheBound()` (see below) so the kit checks the inexact total, that no other
  tenant's row shows up, and that the flag does not leak into the next search.

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

The event is **not stoppable**: `stopPropagation()` is a no-op and `isPropagationStopped()` is always `false`, so every
listener runs and a listener that allows (even at a higher priority) can never skip a later deny. A listener that
throws drops the group (SearchKit logs the class names), never fails open.

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
and database:

- refusals give `null` (no tenant, feature off, malformed and unknown scopes, a host deny — also after a listener that
  allows and stops propagation);
- no other-tenant row, no row your access check hides and no archived row is ever shown or counted, and hidden rows —
  filling the window, or (1.10.1) placed before and after the visible ones in a window of at most 10 rows — either
  change nothing (required when `hiddenRowsFilteredInSql()` is `true`) or make the total inexact, never a smaller total
  still marked exact;
- literal wildcards (`%`, `_`, `!`, `\`), the limit and the visible total, bounded results pages;
- recents: a visible row resolves; a hidden, archived or other-tenant row, another URL or kind, no tenant and the
  feature off give `null`;
- `sensitive()` matches what the test declares, and a sensitive group is off while recents keep titles;
- a query budget, and worker safety: one provider instance, two viewers alternately, and (1.10.0) one definition
  instance that must render every search — other viewers, and a changed row when `changeSeededText()` is implemented
  — exactly like a fresh instance, so display data memoised on the instance fails the kit;
- `prepareItems()` (1.10.0): called once per page with exactly the shown rows — never a hidden, host-denied or
  off-page row — before `toPreparedItem()` renders them with the same context; once for a resolved recent; never when
  nothing is shown; at most `prepareItemsBudget()` statements (none for the default no-op);
- bounded candidates (1.11.0, with `seedBeyondTheBound()`): an inexact total for that search only.

A definition that skips its per-row check or its tenant condition fails the kit. `sensitiveTitles()` and
`supportsRecents()` are self-declared by your test, so the kit only checks that the definition is consistent with them. Run it on MySQL, MariaDB and
PostgreSQL in CI. The kit's `QueryCountingMiddleware` works with DBAL 3.8+ and DBAL 4 (1.11.0; before, it was a
fatal error on DBAL 3).

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
    protected function seed(int|string $tenant, string $text): int { return $this->insert($tenant, $text); }
    protected function seedHidden(int|string $tenant, string $text): int { return $this->insert($tenant, $text, private: true); } // the access check hides it
    protected function seedArchived(int|string $tenant, string $text): int { return $this->insert($tenant, $text, archived: true); }
    protected function reference(int|string $id): SearchReference { return new SearchReference('/tasks/' . $id, 'tasks', 'acme_task_show', ['id' => (string) $id]); }
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
| `seed($tenant, $text): int\|string` | One row of the tenant, visible to its viewers, whose searchable text contains `$text` (it may contain `%`, `_`, `!`, `\`); returns its id. |
| `seedHidden($tenant, $text): int\|string` | One row of the tenant that your **per-row access check** hides from the viewers of `actAs()` (and the SQL does not filter). |
| `seedArchived($tenant, $text): int\|string` | One archived or soft-deleted row of the tenant (filtered in SQL). Not needed when `hasArchivedState()` is `false`. |
| `hasArchivedState()` | 1.10.0. Default `true`; return `false` when the records have no archived or deleted state: the archived checks are then skipped with a message and `seedArchived()` is never called. A 1.9 test whose `seedArchived()` calls `markTestSkipped()` still skips (now with a hint to declare `hasArchivedState()` `false`). |
| `reference($id): SearchReference` | The recent the browser stores for a row (URL, kind, route and parameters). |
| `supportsRecents()` | Default `true`; `false` when the definition has no `idField()` / `referenceId()` (every reference must then give `null`). |
| `sensitiveTitles()` | Default `false`; must equal the definition's `sensitive()`. |
| `actAs($tenant, $userIdentifier)` | Makes the definition resolve this viewer and tenant (null: none), feature on. |
| `disableFeature()` | Turns the feature (or `view`) off for the current viewer. |
| `queryBudget()` | Most statements per search (default 2), `prepareItems()` included. |
| `prepareItemsBudget()` | 1.10.0. Most statements of one `prepareItems()` call (default 1). |
| `hiddenRowsFilteredInSql()` | 1.10.1. Default: the definition's `sensitive()`. Return `true` when `baseQuery()` itself filters out every row `seedHidden()` creates; the kit then asserts that hidden rows change nothing at all (rows, total, exactness). A sensitive group must filter in SQL; it may override the hook to `false` only when PHP or a host event alone can decide the visibility, and then documents that it accepts the side channel. |
| `seedBeyondTheBound($tenant, $text): bool` | 1.11.0, optional. Seeds rows of the first tenant so that a search for `$text` hits your definition's own bound (it calls `markPartial()`) and still shows at least one row; returns `true`. The kit then asserts an inexact total on the palette and the results page, no other tenant's row, and an exact total for the next search, in the second tenant, on the same provider instance. Make the bound reachable only by what the hook seeds (e.g. count only the boards that match the text), or reset the database between tests, so the other contract tests still see exact totals. Default `false`: the check is skipped. |
| `changeSeededText($id, $text): bool` | 1.10.0, optional. Changes a seeded row's searchable text (the new text contains the old one) and returns `true`; the worker-safety check then also renders a changed row on the reused definition. Default `false`: that step is skipped, since the kit cannot change a row on its own. |

Each test seeds rows under a fresh random marker, so the tests need no cleanup and never see each other's rows. Add
your own tests next to the kit's (archived rows, your access event, scopes).

## Rules

- **Stateless**: FrankenPHP worker mode with kernel reset off. Resolve the tenant and the viewer on each call; keep
  nothing in properties between calls (display data for the shown rows goes in the `SearchItemContext`).
- **Filter in SQL first** (tenant, visibility, archived and deleted rows), then check each candidate (batched).
- **Oracle-free**: never return a count, an error or a hint for a refusal; SearchKit returns `null`.
- **Escape**: SearchKit escapes titles and subtitles; never put secret values, notes or bodies of sensitive records in
  them, and set `sensitive()` when titles are private.
- **Optional dependency**: SearchKit stays in `suggest` and `require-dev`; wire the definition only when it is
  installed.
