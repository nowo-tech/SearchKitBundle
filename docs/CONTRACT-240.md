# Contract 240 — "search everywhere" ports

SearchKitBundle **1.8.0** defines the ports that other bundles implement so that the header search, the palette and the
results page find their content — **always filtered by the viewer's permissions, deny by default**. This document is
the reference the secondary bundles of Beacon spec 240 are built against: ManualKitBundle, DashboardMenuBundle,
ChatKitBundle, UptimeMonitorBundle, ApiStudioBundle, TaskBoardBundle and WikiBundle.

The ports are stable from 1.8.0 and follow semantic versioning: adding a port or an optional method of a new marker
interface is a minor change; changing a signature below is a major one.

## Table of contents

- [The rule every port follows](#the-rule-every-port-follows)
- [Ports at a glance](#ports-at-a-glance)
- [Access checker](#access-checker)
- [Search provider (content of a bundle)](#search-provider-content-of-a-bundle)
  - [Typed filters](#typed-filters)
  - [Totals](#totals)
  - [Ranking](#ranking)
  - [Paging on the results page](#paging-on-the-results-page)
  - [Recents](#recents)
  - [Filter value suggestions](#filter-value-suggestions)
- [Synonyms](#synonyms)
- [Page sources and menu keywords](#page-sources-and-menu-keywords)
- [Palette actions](#palette-actions)
- [Per-bundle contracts](#per-bundle-contracts)
  - [ManualKitBundle — synonyms and tags](#manualkitbundle--synonyms-and-tags)
  - [DashboardMenuBundle — project menus and keywords](#dashboardmenubundle--project-menus-and-keywords)
  - [ChatKitBundle — channels and messages](#chatkitbundle--channels-and-messages)
  - [UptimeMonitorBundle — monitors and incidents](#uptimemonitorbundle--monitors-and-incidents)
  - [ApiStudioBundle — collections and requests](#apistudiobundle--collections-and-requests)
  - [TaskBoardBundle — boards and tasks](#taskboardbundle--boards-and-tasks)
  - [WikiBundle — pages](#wikibundle--pages)
- [Checklist for a provider](#checklist-for-a-provider)
- [Worker mode and DDD hosts](#worker-mode-and-ddd-hosts)

## The rule every port follows

> Everything respects the viewer's permissions in context, **deny by default**. Nothing may leak through counts,
> snippets, titles, typed filters, suggestions or recents.

Concretely, for every port:

1. **A row the viewer may not open does not exist for that viewer.** Filter it **in the query** (SQL `WHERE` / `JOIN`
   on membership, tenant, ACL), before counting, before ranking and before building a snippet. Never fetch then hide.
2. **No access rule, no rows.** A provider built without its access port (checker, membership repository, tenant
   resolver) returns `null`. An optional SearchKit provider shipped by a bundle is **off by default** and refuses to
   run when its access port is missing.
3. **Unknown and forbidden look the same.** An unknown or forbidden scope id, filter value or reference answers exactly
   like "no match": `null` / `[]`, the same JSON, no exception, no 403, and comparable work where practical.
4. **Snippets come from visible data only.** A subtitle never names a hidden record (e.g. the private channel a
   message was moved from, the project of an issue the viewer lost access to).
5. **Totals are exact for the viewer or absent.** Count with the same permission filter as the rows and mark the group
   `exactTotal: true`; otherwise SearchKit caps the total to the rows you returned.
6. **No query text in logs.** Log provider and exception classes only (the query may be personal data).

## Ports at a glance

| Port (namespace `Nowo\SearchKitBundle\Contract`) | Tag (autoconfigured) | Implemented by |
| --- | --- | --- |
| `SearchAccessCheckerInterface::decide(SearchAccessRequest): ?bool` | `nowo_search_kit.access_checker` | The host (route access rule); a bundle may ship one for its own routes |
| `SearchProviderInterface::search(SearchQuery): ?SearchGroup` (1.0) | `nowo_search_kit.provider` | Every content bundle |
| `IdentifierAwareSearchProviderInterface` (1.7, marker) | — | Providers that read only `SearchQuery::$userIdentifier` |
| `FilterableSearchProviderInterface::supportedFilters(): list<string>` | — | Providers that apply typed filters |
| `PaginatedSearchProviderInterface::groupKey()` / `searchPage(SearchQuery, int $page, int $perPage)` | — | Providers whose group pages on the results page |
| `SearchReferenceResolverInterface::resolve(SearchReference, string $userIdentifier, ?string $locale): ?SearchItem` | `nowo_search_kit.reference_resolver` | Providers whose rows can be recent picks |
| `SearchFilterValuesProviderInterface::supportsKey()` / `values(...)` | `nowo_search_kit.filter_values` | Providers that suggest filter values |
| `SearchSynonymProviderInterface::synonymsOf(string $word, string $locale): list<string>` | `nowo_search_kit.synonym_provider` | Glossaries (ManualKit), host dictionaries |
| `SearchPageSourceInterface::pages(SearchQuery): iterable<SearchPage>` | `nowo_search_kit.page_source` | DashboardMenu (project menus / tabs), hosts |
| `SearchActionProviderInterface::actions(SearchContext, ?string $scope): iterable<SearchAction>` | `nowo_search_kit.action_provider` | Bundles with palette commands, hosts |
| `SearchKeywordsAwareInterface::getSearchKeywords(): ?string` (or the same method by duck typing) | — | Menu items (DashboardMenu) |

Value objects (namespace `Nowo\SearchKitBundle\Model`): `SearchQuery` (text, filters, terms, locale, scope, limit,
user identifier, fuzzy), `SearchFilter`, `SearchTerm`, `SearchGroup` (+ `exactTotal`), `SearchItem` (+ `score`, `date`,
`shortcut`), `SearchAccessRequest`, `SearchReference`, `SearchPage`, `SearchAction`, `SearchFilterValue`,
`SearchContext`.

Each tag constant is on its interface (`SearchAccessCheckerInterface::ACCESS_CHECKER_TAG`, …) with a distinct name, so a
class may implement several ports without an ambiguous `TAG` constant.

## Access checker

```php
interface SearchAccessCheckerInterface
{
    public function decide(SearchAccessRequest $request): ?bool;   // true allow, false veto, null no decision
}
```

SearchKit asks every checker for every registry page, page-source entry, palette action, recent reference and — with
`access.filter_all_rows` — every provider row. Allowed only when **one checker says true and none says false**; no
checker, only `null`, or a checker that throws → **denied**.

`SearchAccessRequest` carries `userIdentifier`, `url`, `route` (null for an external URL), `routeParameters` (scalars,
no `_` keys), `kind` (`page`, `action`, `recent`, `row`), the optional `attribute` / `subject` of a registry entry and
the search `scope`. A bundle that ships routes may ship a checker that answers for **its own routes only** and returns
`null` for any other route (so the host's rule still decides those).

## Search provider (content of a bundle)

A content bundle (ChatKit, UptimeMonitor, ApiStudio, TaskBoard, Wiki) exposes its content either as a ready SearchKit
provider **behind an option** (`<bundle>.search_kit.enabled: false` by default) or as a query port the host wraps. Both
follow this shape:

```php
#[AsTaggedItem(priority: 40)]
final readonly class TaskSearchProvider implements
    FilterableSearchProviderInterface,      // typed filters it applies
    PaginatedSearchProviderInterface,       // results page paging
    IdentifierAwareSearchProviderInterface, // reads only $query->userIdentifier
    SearchReferenceResolverInterface        // recents
{
    public function __construct(private TaskSearchPort $port, private ?TaskAccessPort $access) {}

    public function search(SearchQuery $query): ?SearchGroup
    {
        if ($this->access === null) {
            return null;                                   // no access rule, no rows
        }
        $page = $this->port->search(new TaskSearchCriteria(
            viewer: $query->userIdentifier,                // membership enforced IN the query
            terms: array_map(fn ($t) => $t->text, $query->terms()),
            scope: $query->scope,                          // unknown / forbidden → no rows
            filters: $query->filters,
            limit: $query->limit,
        ));

        return $page->rows === [] ? null : new SearchGroup('tasks', 'Tasks', $page->rows, $page->visibleTotal, null, null, true);
    }
    // supportedFilters(), groupKey(), searchPage(), resolve() …
}
```

- Match on **every** alternative of `SearchQuery::terms()` (the typed text first, then synonyms tagged with their
  locale). In memory, `SearchQuery::matches()` / `score()` do it with case + accent folding (and typos when the host
  turned `matching.fuzzy` on). In SQL, `OR` the terms (bounded: at most `synonyms.max_terms`, 24 by default).
- Use `SearchQuery::$locale` for labels; never trust `$scope` or filter values.
- Bound every query (`LIMIT $query->limit`, indexes, FULLTEXT where the engine has it).

### Typed filters

The host lists the keys (`nowo_search_kit.filters.keys`). A query with a filter key runs **only** the providers whose
`supportedFilters()` lists that key; every other provider is skipped. A query with filters and no text reaches only
`FilterableSearchProviderInterface` providers. The engine handles `type:` itself (it keeps or drops groups by key).

| Key | Meaning (convention) | Resolution rule |
| --- | --- | --- |
| `is` | state: `open`, `closed`, `unread`, `down`, … | unknown state → no rows |
| `assignee` | `me` or a user handle | `me` = the viewer; a user the viewer cannot see → no rows (same as nobody) |
| `project` | project id / slug | a project the viewer may not open → no rows (same as unknown) |
| `type` | group key (engine) | unknown key → no groups |

`SearchFilter` has `key`, `value` (≤ 100 characters) and `negated` (`-key:value`). Apply a negated filter as an
exclusion, with the same rule: excluding an invisible value excludes nothing visible.

### Totals

Return `new SearchGroup($key, $label, $rows, $visibleTotal, $moreUrl, $moreLabel, exactTotal: true)` only when
`$visibleTotal` was counted with the same permission filter as the rows. Without `exactTotal`, SearchKit shows
`min(total, count(rows))`. The results page tabs show **returned rows**, never a total.

### Ranking

Set `SearchItem::$score` (relevance in ]0, 1], e.g. `$query->score($title)` or your FULLTEXT score normalised) and
`SearchItem::$date` (last change). SearchKit sorts inside the group by score + recency boost
(`ranking.recency_weight` × 0.5^(age / `ranking.recency_half_life_days`)) and builds the *Top results* section of the
results page from scored rows only. Rows are ranked **after** the permission filter.

### Paging on the results page

`PaginatedSearchProviderInterface::groupKey()` returns the key of your group; `searchPage($query, $page, $perPage)`
returns that page (permission filter before `LIMIT` / `OFFSET`, so a page never has a gap that reveals a hidden row).
No total is needed: the page shows "next" while a page comes back full.

### Recents

With `recent.revalidate`, the browser stores only `{url, kind}`. On every display SearchKit matches the URL to a route,
asks the access checkers (deny by default), then asks the reference resolvers. Implement
`SearchReferenceResolverInterface::resolve()` for your routes: re-check the record **now** for that viewer, return the
row (same URL) or `null` for unknown / deleted / forbidden alike. Never cache the decision.

### Filter value suggestions

`SearchFilterValuesProviderInterface::values($key, $prefix, $userIdentifier, $scope, $limit)` returns only values the
viewer may see (members of the viewer's projects, projects the viewer may open, states). Unknown key, unknown prefix
and forbidden prefix all answer `[]`.

## Synonyms

```php
interface SearchSynonymProviderInterface
{
    /** Words IN $locale that mean the same as $word (in whatever language $word is). */
    public function synonymsOf(string $word, string $locale): array;
}
```

SearchKit asks the UI locale first, then the other `synonyms.locales`, for the whole text and for each word (a word's
synonym replaces it in place). Every alternative is a `SearchTerm {text, locale, synonym: true}`; a match in the UI
locale scores 0.85 of the typed text, another locale 0.75; fuzzy matching never applies to synonyms. Keep it pure and
in memory: it runs on every keystroke. The built-in dictionary (`synonyms.builtin_dictionary`) is one set of concept
groups across en es it fr pt de nl (`Resources/synonyms/concepts.php`).

## Page sources and menu keywords

- `nowo_search_kit.pages` (host configuration): `{id, label, translation_domain, route, route_parameters, trail, icon,
  keywords, scope: none|required, scope_parameter, attribute, subject}`. Keywords are a translation key whose message is
  a comma-separated list per locale, matched in every `page_registry.locales`, never shown.
- `SearchPageSourceInterface::pages(SearchQuery)` adds entries computed at runtime (`SearchPage` VOs), e.g. the tabs of
  the project in scope. SearchKit checks each entry with the access checkers **before** matching it.
- `menu_pages.keywords: {route: translation key}` and `getSearchKeywords()` on a menu item add keywords to the built-in
  Pages group (`menu_pages.check_access: true` also runs the access checkers on the menu entries).

## Palette actions

`SearchActionProviderInterface::actions(SearchContext $context, ?string $scope)` returns `SearchAction {label, url,
keywords, icon, shortcut, context}` — **GET links only** (the bundle cannot run anything). Each action is listed only
when the access checkers allow its URL. The Actions group comes first in the results, `>` lists actions only, and the
empty-query list comes from the providers (the static `quick_actions` remain the fallback when there is none).
`shortcut` is a display hint; `context` is shown under the label (e.g. the project it acts on).

## Per-bundle contracts

Each bundle below keeps its own domain types; SearchKit is a **suggested** dependency (`suggest` in composer.json, the
provider class is registered only when `nowo-tech/search-kit-bundle` is installed **and** the bundle's
`search_kit.enabled` option is true).

### ManualKitBundle — synonyms and tags

- Reads synonyms through `SearchSynonymProviderInterface` when SearchKit is installed, through its own
  `ManualSynonymProviderInterface` with the same `synonymsOf(word, locale)` shape otherwise; terms of every locale, the
  reader's locale first.
- Matches chapters on the manifest `tags`, `<!-- tags: -->` per section and the expanded terms; the manual's search box
  and its SearchKit provider share the code.
- A chapter reached through a synonym keeps the manual's access rules: the provider filters chapters with the same
  access check as the chapter route **before** matching; a chapter the reader may not open is never matched, counted or
  suggested.
- May implement `SearchSynonymProviderInterface` to expose its glossary to the rest of the search (opt-in).
- Group key `manual`; implements `IdentifierAwareSearchProviderInterface` and `SearchReferenceResolverInterface`.

### DashboardMenuBundle — project menus and keywords

- Implements `SearchPageSourceInterface` for project menus / tab lists: one `SearchPage` per tab with
  `scope: required`, `scope_parameter` = the project route parameter, filtered by the **same permission checkers** as
  the rendered menu (an entry the menu would hide is never yielded).
- `MenuItem::getSearchKeywords(): ?string` (comma-separated, translated) read by SearchKit's Pages group by duck typing
  (no hard dependency); `getRouteName()` keeps working with `menu_pages.keywords`.
- `MenuExtension` keeps implementing the `getMenuTree()` / `getHref()` pair (`MenuTreeSourceInterface`).

### ChatKitBundle — channels and messages

- A query port `ChatSearchPortInterface` (ChatKit namespace) with membership enforced **in the query**: channels the
  viewer belongs to, messages of those channels (FULLTEXT on the body where available), direct conversations of the
  viewer only.
- Optional SearchKit provider behind `nowo_chat_kit.search_kit.enabled` (default false): groups `channels` and
  `messages`, filters `channel:` (a channel the viewer is not in → no rows) and `from:` (`me` or a participant), exact
  totals counted with the membership join, `PaginatedSearchProviderInterface` for `messages`, recents for both.
- Snippets: the message excerpt and the channel name only; never a hidden channel name, never a deleted message.

### UptimeMonitorBundle — monitors and incidents

- A query port filtered by tenant and the bundle's access checker: monitors and incidents of the tenants the viewer may
  open.
- Optional SearchKit provider (`search_kit.enabled`, default false): groups `monitors` and `incidents`, filters `is:`
  (`up`, `down`, `paused`, `open`, `resolved`) and `project:`; exact totals; recents for monitor pages.
- Status in a `SearchBadge` (tone `success` / `error` / `warning`), computed from data the viewer may see.

### ApiStudioBundle — collections and requests

- A query port filtered by workspace membership: collections, requests and environments of the viewer's workspaces.
  Never search or return secret values (variables, headers, tokens): titles, methods and paths only.
- Optional SearchKit provider (`search_kit.enabled`, default false): groups `api_collections` and `api_requests`,
  filters `type:` (engine) and `project:` / `workspace:`; recents for request pages.

### TaskBoardBundle — boards and tasks

- Query port with board membership in the query; groups `boards` and `tasks`; filters `is:` (`open`, `closed`),
  `assignee:` (`me` or a member of a board the viewer belongs to), `project:`; exact totals; paging; recents; filter
  values for `assignee:` and `project:` from the viewer's boards only.

### WikiBundle — pages

- Query port with the space / page ACL in the query (inherited restrictions included); group `wiki`; filter `project:` /
  `space:`; snippets from the page excerpt the viewer may read; exact totals; paging; recents.
- A page reached through a synonym keeps its ACL (the filter runs before matching).

## Checklist for a provider

- [ ] Off by default; refuses to run without its access port.
- [ ] Permission filter in the query, before count, rank and snippet.
- [ ] Unknown and forbidden scope / filter value / reference → the same empty answer.
- [ ] `supportedFilters()` lists only the keys it really applies.
- [ ] `exactTotal: true` only for a permission-filtered count.
- [ ] `score` / `date` set from visible data.
- [ ] Recents: `resolve()` re-checks now, same URL, `null` otherwise.
- [ ] No query text, no record data in logs.
- [ ] Stateless service (FrankenPHP worker mode, `kernel.reset` off): no per-request property, no static cache keyed by
      user; Igor clean.
- [ ] Functional test of the permission matrix (two viewers, one forbidden record): rows, totals, filters probing
      (forbidden value vs unknown value: byte-identical JSON), recents.

## Worker mode and DDD hosts

All ports take plain values (`SearchQuery`, `SearchContext`, `SearchAccessRequest`): no Symfony `Request`, no security
user (REQ-ARCH-001 rule 3). Providers implementing `IdentifierAwareSearchProviderInterface` also run through
`SearchEngineInterface::searchFor()` (CLI, API, another UI). Services must be stateless; SearchKit's own 1.8.0 services
are (Igor clean, kept-alive kernel tests in `tests/Functional/SearchEverywhereTest.php`).
