# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.12.1] - 2026-11-26](#1121---2026-11-26)
- [[1.12.0] - 2026-11-23](#1120---2026-11-23)
- [[1.11.3] - 2026-11-20](#1113---2026-11-20)
- [[1.11.2] - 2026-11-20](#1112---2026-11-20)
- [[1.11.1] - 2026-11-19](#1111---2026-11-19)
- [[1.11.0] - 2026-11-19](#1110---2026-11-19)
- [[1.10.1] - 2026-11-19](#1101---2026-11-19)
- [[1.10.0] - 2026-11-19](#1100---2026-11-19)
- [[1.9.0] - 2026-11-19](#190---2026-11-19)
- [[1.8.1] - 2026-11-17](#181---2026-11-17)
- [[1.8.0] - 2026-11-17](#180---2026-11-17)
- [[1.7.0] - 2026-11-11](#170---2026-11-11)
- [[1.6.0] - 2026-11-07](#160---2026-11-07)
- [[1.5.0] - 2026-11-07](#150---2026-11-07)
- [[1.4.0] - 2026-11-06](#140---2026-11-06)
- [[1.3.1] - 2026-10-31](#131---2026-10-31)
- [[1.3.0] - 2026-10-30](#130---2026-10-30)
- [[1.2.1] - 2026-10-30](#121---2026-10-30)
- [[1.2.0] - 2026-10-23](#120---2026-10-23)
- [[1.1.1] - 2026-10-22](#111---2026-10-22)
- [[1.1.0] - 2026-10-18](#110---2026-10-18)
- [[1.0.3] - 2026-10-18](#103---2026-10-18)
- [[1.0.2] - 2026-10-16](#102---2026-10-16)
- [[1.0.1] - 2026-10-16](#101---2026-10-16)
- [[1.0.0] - 2026-10-16](#100---2026-10-16)

## [Unreleased]

### Added

- **Typed filters for entity definitions** (Beacon spec 240 FR-034): `EntitySearchDefinition::supportedFilters()`
  (default `[]`) and `applyFilters(QueryBuilder, list<SearchFilter>, tenant, SearchQuery): bool` (default `false`:
  deny). `EntitySearchProvider` now implements `FilterableSearchProviderInterface` with the definition's keys (`type`,
  invalid keys and duplicates dropped): a definition that declares `is` / `assignee` runs for `is:open assignee:me`,
  also as a **filters-only** query (no text: no match condition, the filters alone select the rows). The filters are
  applied **in SQL** after the scope and before the text match, the candidate window, the total and the paging, so
  totals and pages are those of the filtered rows. Negation (`-key:`) is passed through (`SearchFilter::$negated`).
  `applyFilters()` returning `false` gives `null`, like no match; a parameter named `nowo_search_*` bound by it is
  refused with a `LogicException`. A filter key the definition does not declare still gives `null`, also when the
  provider is called directly (deny by default).
- `Search\Query\FilterCondition`: `equals()` adds `field = :p` or, negated, `(field IS NULL OR field <> :p)` and binds
  the resolved value (field and parameter names checked, `nowo_search_*` refused); `matchNothing()` adds `1 = 0` for a
  value that names nothing the viewer may see, so it runs the same statement as a value with no match.
- Contract kit: six tests (`testContractUndeclaredFiltersGiveNull`, `…FiltersApplyInSqlBeforeTheTotal`,
  `…NegatedFiltersReturnTheOtherRows`, `…HiddenFilterValuesLookMissing`, `…FilterValuesAreBound`,
  `…FilterOnlyQueriesStayInsideTheFilter`) and optional hooks `filterKey()`, `seedForFilter()`,
  `matchingFilterValue()`, `seedHiddenFilterValue()`, `filteredQuery()`. With `filterKey()` null (default) only the
  undeclared-filter check applies; the others pass as not applicable (never skipped). A hidden value must answer like
  a missing one: same rows, total, exactness and number of SQL statements; a filter value must never appear in the
  SQL text. `RenderRecorder` forwards the two new hooks.

## [1.12.1] - 2026-11-26

### Fixed

- The `📦 [name] script loaded` console banner now prints once per page, even when the script is loaded twice: per-name once-guard (a `Set` on `globalThis`) in `logger.ts`, with a Vitest test and rebuilt assets (REQ-ASSETS-005).

### Changed

- CI (not shipped): the Dependabot asset rebuild (`.github/workflows/dependabot-assets.yml`, was `dependabot.yml`) is split into a read-only build job (no persisted credentials, `pnpm install --frozen-lockfile --ignore-scripts`) and a commit job, the only one with write access, that never installs or builds: it accepts only regular files under the asset paths from the build artifact, commits them and dispatches `ci.yml` (now `workflow_dispatch`-able) so the required checks run on the rebuilt head (audits ZAWD-M1, ZAWD-L2). It now runs on Dependabot npm branches only.

## [1.12.0] - 2026-11-23

### Documentation

- `Test\Internal\RenderRecorder` (test kit): its 24 public methods have PHPDoc (`make validate-phpdoc` passes again). Comments only, no code change.

### Fixed

- A new strict `TranslationCatalogueTest` fails on a non-en locale missing a key, on a non-en value identical to English (any length, placeholders included; values without letters are skipped) unless allow-listed per locale (brands, units, protocols, loanwords, true cognates), and on stale allow-list entries. No translation changed.

### Changed

- CI: new `release-gates` job runs the four release gates (`make rector-dry`, `validate-phpdoc`, `validate-translations`, `check-twig-extra`) in full mode, so a gate failure shows on the PR instead of at release time (CI only, no change to shipped code).
- Demo: runs in UiKit's demo platform shell (`nowo-tech/ui-kit-bundle` ^2.60; shared top bar, aside, page header and section cards; no demo stylesheet or `<style>`). Demo only, no bundle change.

### Added

- **Console**: `nowo-search.js` logs one line on load, `📦 [search-kit] script loaded, build: 2026-11-23 14:05 UTC`, with the house `createBundleLogger`. The build time is read from the committed `build-info.json` (`{sourceHash, builtAt}`): it is reused while the sources are unchanged and renewed only when they change, so rebuilding unchanged sources never rewrites the committed assets (no wall-clock stamp, no user data). Quiet switch: `<html data-nowo-console="quiet">` or `globalThis.NOWO_CONSOLE = 'quiet'` prints nothing; the default is unchanged.

## [1.11.3] - 2026-11-20

### Changed

- The results list of the dropdown uses the UiKit thin scrollbar look: size, thumb, thumb hover and track come from the
  `--nowo-ui-scrollbar-*` tokens, with a fallback from the field's own text colour (and `scrollbar-width: thin` where
  `::-webkit-scrollbar` is unsupported) when UiKit is absent. Previously the bar used the border colour and its own geometry.

## [1.11.2] - 2026-11-20

Target: **1.11.2** (patch).

### Fixed

- **Contract kit: no test skips any more because an optional hook keeps its default.** 1.11.0 added
  `testContractBoundedCandidatesGiveAnInexactTotal`, which called `markTestSkipped()` when `seedBeyondTheBound()`
  returned `false` (the default). Every host running its SearchKit contract tests with `--fail-on-skipped` then failed.
  The check now passes as not applicable, with one assertion recorded so PHPUnit does not flag it as risky. The same
  applies to the other kit skips:
  - `testContractNeverShowsArchivedRows` with `hasArchivedState()` false passes instead of skipping;
  - `testContractHiddenRowsBeyondTheWindowChangeNothing` checks a window above 500 rows as a 500-row window (it was
    skipped), and passes as not applicable for a 1-row window.

  Required hooks still fail when missing. A 1.9-style test whose own `seedArchived()` skips still skips, with the
  hint to declare `hasArchivedState()` false. New self-tests run the whole kit with every optional hook at its
  default (with and without an archived state), and CI runs them with `--fail-on-skipped --fail-on-risky` on every
  database. No runtime change.

## [1.11.1] - 2026-11-19

Target: **1.11.1** (patch).

### Fixed

- **Contract kit: `testContractHiddenRowsBeyondTheWindowChangeNothing` no longer depends on the insertion order.** It
  seeded `maxCandidates()` hidden rows between two visible ones, so the matches exceeded the window by two. A
  definition whose order does not follow insertion — for example equal timestamps with a random id tiebreak — could
  sort both visible rows past the window and fail the test by chance ("A visible row must still be found"; about one
  run in 231 with a 20-row window). The kit now seeds `maxCandidates() - 1` hidden rows: the window is still full,
  and at least one visible row is always in it. The other window test,
  `testContractHiddenRowsAroundVisibleOnesNeverShrinkAnExactTotal`, was already order-independent. A new self-test
  runs the whole kit against a definition that orders every hidden row before every visible one (the unluckiest
  order); it failed this test with the 1.11.0 kit. No runtime change.

## [1.11.0] - 2026-11-19

Target: **1.11.0** (minor, additive).

### Added

- **Entity providers: a definition can mark its candidates partial.** A definition that bounds its own scan (for
  example it reads at most N boards of the tenant to build the query) used to report an exact total when the bound
  was hit. Two optional hooks now receive a per-search `SearchCandidateContext`:
  `candidateQuery($tenant, $query, $context)` (default `baseQuery()`) and
  `filterCandidates($rows, $tenant, $query, $context)` (default `filterVisible()`). SearchKit calls them instead of
  `baseQuery()` / `filterVisible()`. When the definition calls `$context->markPartial()`, the group's `exactTotal` is
  false, on the palette and the results page, and SearchKit shows the capped "N+" count. The context is new for each
  search page and each resolved recent and is never kept, so the flag cannot reach another search in FrankenPHP
  worker mode. 1.9 and 1.10 definitions are unchanged: by default nothing is partial. Any decorator of another
  definition (host or test code) must forward the two new hooks, or the inner definition's bound is skipped and the
  total is wrongly exact (UPGRADING). Recents are resolved through `candidateQuery()` with an empty query text.
- **Contract kit**: `testContractBoundedCandidatesGiveAnInexactTotal` and the optional hook `seedBeyondTheBound()`
  (default `false`, the test is then skipped). With the hook, the kit asserts an inexact total for a search that hits
  the definition's bound, no other tenant's row, and an exact total for the next search on the same provider
  instance. The kit's `RenderRecorder` forwards the two new hooks.
- CI: the `database` job also runs on DBAL 3 (MySQL 8.4 and PostgreSQL 16, plus SQLite).

### Fixed

- **Contract kit on DBAL 3**: `QueryCountingMiddleware` caused a PHP fatal error on DBAL 3 ("Declaration of
  CountingConnection::exec(string $sql): string|int must be compatible"), because its connection declared the DBAL 4
  return type of `exec()`. It now declares `int`, which DBAL 3 and DBAL 4 both accept. The entity provider runtime
  already worked on DBAL 3. `doctrine/dbal` is now suggested as `^3.8 || ^4.2` (it was `^4.2`).

## [1.10.1] - 2026-11-19

Target: **1.10.1** (patch).

### Fixed

- **Entity providers: a shrunken total is no longer marked exact.** The total was marked exact whenever the
  *visible* rows did not fill the candidate window. Rows hidden only in PHP (`filterVisible()` or a host deny) that
  came before the visible ones could therefore shrink a total that was still claimed exact. For example, 7 hidden rows
  ahead of 6 visible ones in a 10-row window gave total 3, exact. Now `exactTotal` is false whenever the SQL fetch
  returned `maxCandidates()` rows, whoever may see them. This applies in the palette, on the results page and in the
  "see all" count. The total stays a lower bound, which SearchKit caps to the rows it lists. `SearchWindow::isExact()`
  takes the number of fetched rows as a second argument.
  **Only the exact-total claim is fixed.** In the same example the viewer still sees 3 rows instead of 6: visible rows
  pushed out of the window stay missing. That shrink is still a count side channel until the definition filters its
  hidden rows in SQL (see "Rows hidden only in PHP still use the candidate window" in PROVIDERS.md).

### Added

- **Contract kit**: `testContractHiddenRowsAroundVisibleOnesNeverShrinkAnExactTotal` places hidden rows before and
  after the visible ones in a window of at most 10 rows and requires either no change or an inexact total. The new
  optional hook `hiddenRowsFilteredInSql()` (default `false`) makes it, and
  `testContractHiddenRowsBeyondTheWindowChangeNothing`, require no change at all. Its default is the definition's
  `sensitive()`, so a sensitive group that hides rows only in PHP fails the kit. It passes once it filters in SQL, or
  once it overrides the hook to `false` and documents that it accepts the side channel.

### Deprecated

- `SearchWindow::isExact()` without its second argument (the number of fetched rows) triggers a deprecation; the
  argument is required in 2.0.

## [1.10.0] - 2026-11-19

Target: **1.10.0** (minor, additive).

### Added

- **`EntitySearchDefinition::prepareItems(array $shownRows, SearchQuery $query, SearchItemContext $context)`**: a batch
  hook (default: nothing) called once per search page with exactly the rows the provider returns — after
  `filterVisible()`, after `SearchKitEvents::ITEM_VISIBILITY` and after the page cut — right before they are rendered,
  and once per resolved recent with that row; never when no row is returned. Definitions load display data for those
  ids only, in one statement, with no oracle and no N+1. It must be read-only (no flush, clear or transaction). With
  `access.filter_all_rows`, the engine's URL check runs afterwards and may still drop some of these rows.
- **`SearchEngine`**: a provider whose group key is known (`PaginatedSearchProviderInterface`, every entity provider)
  and excluded by the viewer's `type:` filters is no longer called at all, in the palette and on the results page;
  before, it ran and rendered its rows and the group was dropped afterwards.
- **`EntitySearchDefinition::toPreparedItem(array|object $row, SearchQuery $query, SearchItemContext $context)`**
  (default: `toItem()`) and the new **`Provider\Entity\SearchItemContext`** (`set()`, `get()`, `has()`): a new context
  per page and per resolved recent, passed to `prepareItems()` and then to `toPreparedItem()` for those rows and dropped
  afterwards, so prepared data never lives in a shared service (FrankenPHP worker mode, kernel reset off).
- **Contract kit**: `hasArchivedState()` (default `true`; `false` skips the archived checks with a message and never
  calls `seedArchived()`), `prepareItemsBudget()` (default 1), and the tests `testContractNeverShowsArchivedRows`,
  `testContractPrepareItemsGetsOnlyTheShownRows`, `testContractPrepareItemsRunsForResolvedRecents` and
  `testContractPrepareItemsKeepsTheQueryBudget`. `testContractReusedDefinitionRendersLikeAFreshOne`: one definition
  instance must render alternating viewers (and, with the new optional `changeSeededText()`, a changed row) exactly
  like a fresh instance, so display data memoised on the instance fails the kit. A `seedArchived()` that calls
  `markTestSkipped()` still skips, now with a hint to declare `hasArchivedState()` `false`.

### Changed

- `EntitySearchDefinition::toItem()` is no longer abstract: a definition overrides it or `toPreparedItem()` (with
  neither, the search throws a `LogicException`). `EntitySearchProviderTestCase::seedArchived()` is no longer abstract
  (its default fails the test unless `hasArchivedState()` is `false`). Existing definitions and contract tests are
  unaffected.

## [1.9.0] - 2026-11-19

Target: **1.9.0** (minor, additive).

### Added

- **Entity providers**: the structure of a search provider is now SearchKit's. A bundle extends
  `Provider\Entity\EntitySearchDefinition` and fills only its hooks — `group()`, `label()`, `icon()`, `tenant()`,
  `featureEnabled()`, `searchableFields()`, `baseQuery()` (Doctrine DBAL **or** ORM query builder), `canView()` /
  `filterVisible()`, `toItem()`, `seeAllUrl()`, plus optional `applyScope()`, `applyFullText()`, `minLength()`,
  `maxCandidates()`, `sensitive()`, `idField()` / `referenceId()`. The new `EntitySearchPass` registers one
  `Provider\Entity\EntitySearchProvider` per definition (tag `nowo_search_kit.entity_definition`, autoconfigured,
  attribute `priority`), which runs one fixed flow: group switch → text and min length → tenant (`null`: no results)
  → feature gate → scope (client input: shape-checked, then applied in SQL by the definition, or refused) → full-text
  hook or escaped `LOWER(field) LIKE :p ESCAPE '!'` of every query word → one query of at most `maxCandidates()` rows
  → batched bundle access check → the shared host event → the page out of the visible rows, the visible total within
  the window (exact unless the visible rows fill it; hidden rows never change the total or its exactness) and the "see
  all" link. Any refusal is `null` (no count, no hint). A `nowo_search_*` parameter bound by `baseQuery()`, `applyScope()` or
  `applyFullText()` is refused (`LogicException`). It is a `PaginatedSearchProviderInterface` (results page) and a
  `SearchReferenceResolverInterface` (revalidated recents read again through the same checks). A `sensitive()` group
  is off while recents keep titles in the browser (`Search\Group\SensitiveGroupNotice` logs it once per group and
  container). Group keys start with a lower-case letter. Stateless (FrankenPHP worker mode, kernel reset off). Entity
  providers do not run in `searchFor()`.
- **Shared host access event** `Event\SearchKitEvents::ITEM_VISIBILITY` (`nowo_search_kit.item_visibility`):
  `Event\SearchItemVisibilityEvent`, dispatched once per search page with every candidate the bundle's check kept
  (`Model\SearchSubject`: type and id), the group, the viewer, the tenant, the scope and the locale (never the query
  text). Listeners `allow()`, `deny()` or `denyAll()`; **deny wins**; a denied row is neither shown nor counted. The
  event is not stoppable (`stopPropagation()` is a no-op), so no listener can skip a later deny.
- **`nowo_search_kit.groups.<key>.enabled` / `priority`** (`Search\Group\SearchGroupSettings`), applied by the engine
  to every provider: a switched-off paginated or entity provider does not run and any group with that key is dropped;
  configured priorities sort the groups of an answer (unset counts as 0, the Actions group stays first).
- Helpers in `Search\Query`: `LikePattern` (escape, contains, checked `LOWER(field) LIKE … ESCAPE '!'` conditions),
  `SearchWords` (bounded words; letters-and-digits full-text terms), `ScopeParser` (`prefix:value` scopes as client
  input), `SearchWindow` (bounded candidates, page slice, visible total) and `SeeAllLink`.
- **Provider contract test kit** in `Test`: `EntitySearchProviderTestCase` (refusals give `null`; no other-tenant,
  access-hidden or archived rows, and hidden rows filling the window change nothing; literal `%` `_` `!` `\`; the
  limit and the visible total; recents resolve only visible rows; the `sensitive()` gate; a query budget; two viewers
  alternately on one provider instance), `QueryCounter` and the DBAL `QueryCountingMiddleware`. A definition that skips
  its per-row access check or its tenant condition fails the kit.
- Translation `search.see_all` (7 locales). Container parameters `nowo_search_kit.groups` and
  `nowo_search_kit.recent.safe`.
- `SearchEngine` takes an optional 12th argument, `?SearchGroupSettings $groups` (null: no group settings, as before).

### Changed

- `symfony/event-dispatcher-contracts` ^3.5 is a direct requirement (it already came with `symfony/framework-bundle`).
  `doctrine/dbal` ^4.2 and `doctrine/orm` ^3.3 are suggested (entity providers) and in `require-dev`.
- The development image has `pdo_mysql` and `pdo_pgsql`; CI runs the entity search tests on MySQL 8.4, MariaDB 11.4 and
  PostgreSQL 16 (job `database`), the main suite on SQLite.

### Docs

- New [Write a provider](PROVIDERS.md) guide; USAGE, CONFIGURATION (`groups`) and UPGRADING updated.

## [1.8.1] - 2026-11-17

Target: **1.8.1** (patch).

### Fixed

- Fuzzy matching (`matching.fuzzy: true`) in the page registry lists every typo match of a Beacon-size registry
  (audit SP3-L1). The typo pass used to charge every distinct word of an entry's 7-locale texts plus 16 per text, so
  its 40,000 budget covered about 36 entries, cut in configuration order: 150 entries × 8 keywords × 7 locales, all
  carrying `password`, listed 38 of 150 rows for `paswword`. Now:
  - the typo pass runs tier by tier across every entry without an exact match (all the labels, then the breadcrumbs,
    the UI-locale keywords, the other locales), so a larger registry that reaches the budget drops the least relevant
    texts, not the last entries;
  - a tier that cannot beat an entry's best score (its weight × 0.5, the best typo score) is skipped, which leaves the
    ranking equal to comparing every tier;
  - a tier costs what is done: one per text, one per distinct word (new `SearchQuery::scoreTypos()`, words merged once
    by new `FoldedText::wordsOf()`), and 16 per text only for a multi-word query whose words came close.

  The auditor's shape now lists 150 of 150 rows (500 × 30 × 7: 500 of 500, was 25). The budgets are unchanged
  (`MAX_EVALUATIONS` 100,000, `MAX_FUZZY_EVALUATIONS` 40,000); exact matches are still never lost, access is still
  checked before any scoring, and the ceiling notice holds no query text (it is now logged once per search).
- An exact registry entry scoring below what a label typo can give (0.5) also has its label compared for typos and keeps
  the better score (audit SP3-I1): label "Passwort settings" with the keyword "superclave", query `password` with the
  synonym `clave`, now ranks 0.45 instead of 0.402.
- `TextMatcher::FUZZY_MAX` (0.5) and `TextMatcher::fuzzyWords()` are public; `SearchQuery::mayMatchFuzzily()` is kept
  but no longer used by the registry.

## [1.8.0] - 2026-11-17

Target: **1.8.0** (minor, BC: a 1.7 host keeps working without changes). Beacon spec 240 "search everywhere"
(D-20261117-11): everything respects the viewer's permissions, deny by default.

### Added

- `SearchAccessCheckerInterface` (`decide(SearchAccessRequest): ?bool`): the host's route access rule. Deny by default:
  no checker, only abstentions, a veto or a failing checker deny.
- Page registry `nowo_search_kit.pages` (label, breadcrumb, per-locale keywords never shown, `scope: none|required`,
  `attribute` / `subject`) and `SearchPageSourceInterface` for entries computed at runtime; access checked before
  matching; merged into the Pages group (deduplicated by URL). Menu keywords: `menu_pages.keywords` and
  `getSearchKeywords()` (`SearchKeywordsAwareInterface`); `menu_pages.check_access`, `menu_pages.ranked`.
- Palette actions: `SearchActionProviderInterface` → `SearchAction {label, url, keywords, icon, shortcut, context}` (GET
  links, access-checked); Actions group on top; `>` prefix for actions only; the empty-query list comes from the
  providers (`quick_actions` kept as the fallback).
- Typed filters: `filters.keys`, parser (`key:value`, quoted values, `-key:` negation) filling `SearchQuery::$filters`;
  `FilterableSearchProviderInterface::supportedFilters()` (a provider that does not support a filter key is skipped);
  `type:` handled by the engine; chips in the field and `SearchFilterValuesProviderInterface` autocomplete
  (`GET {prefix}/filters`).
- Matching: `SearchQuery::matches()` / new `score()` with case and accent folding, optional fuzzy matching
  (`matching.fuzzy`, bounded cost), `SearchQuery::terms()` with the synonym alternatives tagged by locale.
- `SearchSynonymProviderInterface::synonymsOf(word, locale)` and a built-in concept-group dictionary across en es it fr
  pt de nl (`synonyms.builtin_dictionary`), UI locale ranked first, cross-locale.
- Ranking: optional `SearchItem::$score` / `$date`, rows sorted inside a group by relevance + recency (`ranking.*`).
- Results page `nowo_search_kit_results` (`{prefix}/results`): tabs per group with the number of returned rows, Top
  results, filter chips, paging through `PaginatedSearchProviderInterface`; template and layout configurable; UiKit
  partials when UiKit is installed; *See all results* as the last row of the dropdown.
- Safe recents (`recent.revalidate`): references only (URL + kind) in the browser, revalidated on every display by
  `POST {prefix}/recent` (CSRF, rate limited) through the access checkers and `SearchReferenceResolverInterface`.
- Leak rules in the engine: `SearchGroup::$exactTotal`, `access.filter_all_rows`.
- `SearchEngine::execute(SearchRequest): SearchResult`, `SearchEngine::page()`; `search()` / `searchFor()` take an
  optional locale.
- `docs/CONTRACT-240.md`: the ports ManualKit, DashboardMenu, ChatKit, UptimeMonitor, ApiStudio, TaskBoard and Wiki
  implement.
- Translations of every new string in the 7 locales.

### Changed

- A group total is capped to the rows the provider returned unless the provider marks it exact (`exactTotal`).
- `SearchQuery::matches()` folds accents.
- Groups with the same key are merged (rows deduplicated by URL).
- `phpunit.xml.dist` sets `memory_limit` to 1G (coverage boots many test kernels).
- `symfony/security-csrf` is now a direct requirement (it was already installed through SecurityBundle).
- Browser bundle `VERSION` 1.1.0 (palette prefix, filter chips and suggestions, see-all row, revalidated recents).
- CI and `make coverage-check`: the PHP line-coverage gate is now **100%** (was 99%). The suite is already at 100%; the gate only stops a regression from merging.

### Security

- Audit SP fixes before release (no GHSA, unreleased code): registry matching cost has a ceiling (terms and texts folded
  once, ≤ 32 keywords per entry and locale, other-locale keywords deduplicated and capped at 64, early stop on a full
  match, substring pre-filter, exact tiers before fuzzy, `PageRegistryProvider::MAX_EVALUATIONS` = 100,000 comparisons
  per search; 500 × 30 × 25 went from 8.5–9.6 s to 29–47 ms, regression test `CostTest` < 200 ms) (SP-M1); the client-supplied `from` context reaches action providers only when
  the viewer may open that page (SP-L1); docs: `filter_all_rows` is a safety net (SP-L2), checkers decide for
  `$request->userIdentifier` (SP-L3), value providers honour `$limit` (SP-I5); the results page runs the engine once
  per view (SP-I2). Delta: registry matching in two passes (exact over every visible entry, then typos on the
  remaining budget, at most 40,000: an exact match is never cut by fuzzy cost, SPd-L1); one joined fold per entry as an
  exact pre-check, edit distances charged per candidate word, words split only for typos; keyword caps documented
  where hosts configure them, debug line when a list is cut (SPd-L2); a 7-locale translated `CostTest` case, CPU time
  best of 3 (12–57 ms without pcov, SPd-I1); the demo e2e warms the worker first (SPd-I2).
- Anti-leak model documented in `docs/SECURITY.md`: filtered before counting, ranking and snippets; unknown and
  forbidden values answer identically; recents hold no titles and are re-checked on every display.

### Demo

- Search everywhere in the demo: access checker, settings pages with English / Spanish keywords, palette actions,
  project tabs, an Issues provider with `is:` / `project:` filters, the results page and revalidated recents; Playwright
  `everywhere.spec.ts`.
- The demo is built on UiKit and FormKit (REQ-DEMO-014/015): the base template extends the UiKit admin layout with UiKit's default theme (no Bootstrap CDN, no `demo.css`, no host tokens), the demo form is a FormKit form themed by the `demo` profile and UiKit's form theme.

### Docs

- `docs/CONTRACT-240.md`, USAGE / CONFIGURATION / UPGRADING / SECURITY sections and Spec Kit spec `specs/240-search-everywhere/` for 1.8.0.
- `docs/FRANKENPHP-WORKER-AUDIT.md`: clearer wording of the kept-alive test row.
- Spec Kit inventory refreshed to 50/50 production units (1.6 / 1.7 contracts, `SearchContext`, min-length and motion modules mapped); README TypeScript branch coverage corrected to 97.82%.
- Refreshed `docs/FRANKENPHP-WORKER-AUDIT.md` (reviewed version, kept-alive section, rule 7 note).

### Tests

- The kept-alive worker test (`DddHostSearchKernelTest`) now runs 1,200 iterations (was 300) with no services resetter, REQ-RUNTIME-002.
- Spec 240: unit tests (parser, matching, fuzzy, synonyms, ranking, access, registry, actions, recents, engine), functional permission matrix and anti-leak tests (counts, snippets, recents, filter probing, kept-alive kernel), Vitest `palette.test.ts`, Playwright `everywhere.spec.ts`.

## [1.7.0] - 2026-11-11

Target: **1.7.0** (minor, BC: every 1.6 host keeps working without changes). REQ-ARCH-001 rules 1, 3 and 5 for a DDD / hexagonal host.

### Added

- `SearchContextScopeResolverInterface::resolveFor(SearchContext $context)`: the scope resolver port without Symfony types. `SearchContext` is a new VO with the user identifier, the path, the route name and scalar route parameters, the locale and the user's roles. The bundle builds it per call from the main request and the signed-in user and never keeps it. Guests and calls outside a request still get no scope and never reach a resolver. When both aliases are set, the new port is the one asked. The scope decision is unchanged: a test runs every wiring over the same users × pages matrix and gets the scopes of the 1.6 port in every cell.
- `SearchQuery::$userIdentifier` (filled from the user on the endpoint path), `SearchQuery::forUserIdentifier()`. The constructor refuses a query with no user and no identifier, an empty identifier (`searchFor('')` too; audit MK: MK-S-L2), or an identifier that is not its user's.
- `SearchEngineInterface::searchFor(string $userIdentifier, string $text, ?string $scope = null)`, aliased to `SearchEngine`, to reuse the fan-out from a CLI or an API. Only providers that implement the new marker `IdentifierAwareSearchProviderInterface` run there, with `$user` null. Every other provider is skipped and a notice is logged, because a 1.x provider may read `$user` (a nullable criterion would match `IS NULL` rows) or `Security::getUser()` (the wrong user). `search()`, the endpoint path, still runs every provider. Audit MK: MK-S-L1.
- `MenuTreeSourceInterface` (`getMenuTree()`, `getHref()`) for the Pages group source. The duck-typed source (DashboardMenuBundle) keeps working.
- `NullScopeResolver` implements both resolver ports.
- Tests: a DDD-host kernel (plain-PHP read model, 1.7 ports only, no services resetter) with 300 iterations for two users in alternation and a memory bound (REQ-RUNTIME-002). Also a scope equivalence matrix for the old port, the new port, both aliases, and one service implementing both.

### Deprecated

- `SearchScopeResolverInterface` (`resolve(Request, UserInterface)`). A resolver that implements only this interface keeps working and triggers a deprecation each time it is asked. It will be removed in 2.0.
- `SearchQuery::$user` (Symfony `UserInterface`). Read `$userIdentifier`. It is now nullable: it is null for a search made by identifier, and the endpoint still fills it. It will be removed in 2.0.
- `SearchEngine::search(UserInterface, …)` stays the endpoint path in 1.x. In 2.0 it will be replaced by `searchFor()`.

### Changed

- `composer.json` now requires `symfony/deprecation-contracts` (`^2.5 || ^3.0`) for `trigger_deprecation()`. It was already installed through Symfony. Audit MK: MK-P-L1.
- Docs: USAGE has a new "Standard Symfony host" section, and its scope resolver and DDD sections now describe the new ports. The README, ARCHITECTURE and the worker audit are updated. The demo uses the new ports.
- Docs: new USAGE section "Using it from a DDD / hexagonal application" (where the bundle sits in a hexagonal host, the ports, events and DTOs to build an anti-corruption layer on, and the known limitations).

## [1.6.0] - 2026-11-07

### Added

- `min_length_exempt_patterns` (default empty): a query shorter than `min_length` that matches one of these regular expressions still searches, in the browser and in `SearchEngine`, e.g. `^#\d+$` for `#7`. It reaches only the providers implementing the new `MinLengthExemptSearchProviderInterface`, with the new `SearchQuery::$belowMinLength` true. The role check, the rate limit and `max_length` are unchanged. Patterns must stay in the subset PCRE and JavaScript read alike; anything else, and any `%env()%` value, fails container compilation. Written as a map, only its values are kept; the browser ignores a configuration that is not a list of strings instead of failing to mount. `\w \W \b \B` (locale-dependent in PCRE) and repeats of zero (`{0}`, read differently by PCRE2 10.42-10.44) are outside the subset. See [CONFIGURATION](CONFIGURATION.md#queries-exempt-from-min_length). Target: 1.6.0. Audit HZ (2026-11-07): Pass (good) / Low.

## [1.5.0] - 2026-11-07

### Changed

- **Search popover motion:** opens and closes on UiKit's `--nowo-ui-motion-small*`, `--nowo-ui-motion-lift` and easing tokens (own fallbacks: 150 ms in, 110 ms out, 4 px lift). The close waits for the fade before `hidden`, with a timer if `transitionend` never fires; the phone backdrop fades with it. Reduced motion (`prefers-reduced-motion`, `html[data-motion="reduce"]`) keeps an 80 ms fade with no movement, where the popover had no animation before; `html[data-motion="full"]` keeps the full effect. No `!important`.
- **Turbo:** running fades finish on `turbo:before-cache`, so that a cached page never holds a half-closed surface. Dropdown: audit finding HW-L5.

## [1.4.0] - 2026-11-06

### Added

- `toggle_icon_template` (default `null`): a Twig partial rendered inside the `nowo_search_toggle_icon` block instead of
  the three state icons, so a morphing icon needs no `templates/bundles/` override. Default output is unchanged. Exposed
  through `nowo_search_kit_toggle_icon_template()`; the path is not part of the browser configuration. See
  [UPGRADING](UPGRADING.md#to-140).

## [1.3.1] - 2026-10-31

Requires FormKit ^2.17; local label:false workaround removed (forms render identically). See [UPGRADING](UPGRADING.md#to-131).

### Changed

- **`nowo-tech/form-kit-bundle` `^2.17`:** FormKit 2.17 keeps an explicit `'label' => false` through `FormOptionsMerger`, so the local `FormKitFieldTrait::addKitField()` workaround is removed and `SearchFieldType` add their fields with FormKit's own `FormOptionsTrait::addWithDefaults()` (same options). The rendered forms are unchanged (hidden labels, translations, field names, ids and CSRF tokens). Demo lock updated.

## [1.3.0] - 2026-10-30

Page forms are FormKit types with a bundle FormKit profile; requires nowo-tech/form-kit-bundle (REQ-TWIG-005 rule 11). See [UPGRADING](UPGRADING.md#to-130).

### Changed

- **The search field is a FormKit Type (REQ-TWIG-005 rule 11):** `nowo-tech/form-kit-bundle` (`^2.16`) is now a required dependency. `SearchFieldType` carries `#[FormKitConfig('search_kit')]` + `FormOptionsTrait` and adds `q` through FormKit, themed by the FormKit profile `nowo_form_kit.profiles.search_kit` the bundle prepends (translation domain `NowoSearchKitBundle`, no auto help / placeholder keys). Host override: define `nowo_form_kit.profiles.search_kit` yourself. The rendered input (`nowo-search__input`, `data-part`, no label) is unchanged.

## [1.2.1] - 2026-10-30

Bundle routes are opt-in again: controllers are no longer autoconfigured, so a host's routing.controllers import does not expose them (REQ-SF-004 rule 7). See [UPGRADING](UPGRADING.md#to-121).

### Changed

- **Developer tooling (REQ-CS-001):** `make validate-phpdoc` is read-only and now part of `make release-check`: it fails when a class or public method in `src/` has no PHPDoc block (the former `@return` check asked for tags that `cs-fix` (`no_superfluous_phpdoc_tags`) removes; `make fix-phpdoc` is gone).

### Fixed

- **Routes are opt-in (REQ-SF-004 rule 7):** `SearchController` is no longer autoconfigured nor `#[AsController]`; it is tagged `controller.service_arguments` explicitly. On Symfony >= 7.3 the host's default `config/routes.yaml` (`routing.controllers`) no longer registers `nowo_search_kit_search` on `/` without the `/search` prefix; the route only exists when the host imports `@NowoSearchKitBundle/Resources/config/routes.php`.

## [1.2.0] - 2026-10-23

### Added

- `createSearchKitController(Controller, { onState })`: the Stimulus controller calls `onState(state, root)` on every
  `nowo-search:state` (the initial state included) and stops on disconnect, so a host that morphs its toggle icon no
  longer writes its own controller around `mount()` / `unmount()`.

## [1.1.1] - 2026-10-22

Dependency refresh, wave-1 docs, autoconfigured controller, worktree-safe co-author check. See [UPGRADING](UPGRADING.md#to-111).

### Changed

- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19).
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).
- `SearchController` relies on `#[AsController]` + autoconfigure; the duplicate manual `controller.service_arguments` tag is removed (REQ-SF-003).
- Documentation: wave-1 REQ compliance — README Templates link moved under Additional documentation, tables of contents completed, Spec Kit inventory refresh (40/40, `SearchKitThemeExtension` mapped as FR-TWIG-004).

### Fixed

- `.scripts/check-no-cursor-coauthor.sh` also runs inside git worktrees (`.git` file instead of directory).

## [1.1.0] - 2026-10-18

Header search widget reads UiKit theme slots and tokens when UiKit is installed (optional), new override blocks. See [UPGRADING](UPGRADING.md#to-110).

### Added

- **Optional UiKit theme integration (REQ-UI-003)** — with `nowo-tech/ui-kit-bundle` ^2.2 installed (suggested, not
  required) the header search parts carry the host classes / attributes of the UiKit theme slots of
  `NowoSearchKitBundle`: toggle and scope remove `btn_icon`, scope chip `badge_neutral`, input `input`, dropdown `card`,
  footer `muted` (host part only, never UiKit framework defaults). Twig functions `nowo_search_kit_ui_class()` /
  `nowo_search_kit_ui_attrs()` (`''` without UiKit).
- Blocks `nowo_search_scope`, `nowo_search_field`, `nowo_search_shortcut`, `nowo_search_popover` (one-block `@!`
  overrides); root attribute `data-nowo-ui-bundle="NowoSearchKitBundle"`.
- `docs/TEMPLATES.md`; functional tests of the widget with and without a UiKit stub theme.

### Changed

- `nowo-search.css`: the light / dark defaults of `--nowo-search-bg`, `-fg`, `-muted`, `-border`, `-surface`, `-accent`,
  `-accent-fg` and `-radius` read the UiKit `--nowo-ui-*` tokens first (1.0 values as fallbacks);
  `--nowo-search-accent-soft` is derived from the accent.
- Demo: UiKit host layout and theme tokens (the header search follows them).

## [1.0.3] - 2026-10-18

### Fixed

- The search field placeholder is translated once: `search.html.twig` renders the widget with
  `translation_domain: false`, so the form theme no longer looks the already translated text up again in the
  `NowoSearchKitBundle` domain (a "missing message" in the Symfony profiler, e.g. `Search pages, projects…`).

## [1.0.2] - 2026-10-16

Host-integration patch from the first real adoption (Symfony Beacon). Backward compatible.

### Added

- `nowo_search_root_attr` include variable and `nowo_search_root_attributes` block: extra attributes on the root element
  (e.g. a host `data-controller`) without copying `nowo_search_root`; `class` is appended, values escaped, reserved and
  invalid names ignored.
- `assets.popover_stylesheet` (`null` | `false` | path / http(s) URL): the lazy dropdown stylesheet also works with
  `assets.stylesheet: false` (e.g. a Vite chunk), or can be turned off.
- JavaScript options `SearchOptions` (`loadPopoverStyles`) for `mount(root, options)`, `mountAll(scope, options)`,
  `new GlobalSearch(root, config, options)` and `createSearchKitController(Controller, options)`.
- Phones (< 40rem): a dimmed backdrop (`--nowo-search-backdrop`) behind the open field and dropdown; tapping it closes
  the box. Browser `VERSION` is `1.0.2`.

### Fixed

- Default `--nowo-search-*` tokens are declared inside `:where()` (zero specificity): a plain host
  `.nowo-search { --nowo-search-accent: … }` now overrides them in light and dark mode (it needed `#nowo-search` before).
- `nowo-search:state` is dispatched once right after mount with the initial state, so hosts can sync a custom toggle icon.

## [1.0.1] - 2026-10-16

Compliance patch from an independent audit against the Nowo bundle standards. No configuration, template block or PHP API
change.

### Security

- Row icons are no longer written with `innerHTML`: the new `sanitizeSvg()` (`svg.ts`) parses the server SVG into an
  inert document and appends it as nodes, keeping only drawing elements (no script, `foreignObject`, animation, link or
  external reference) and dropping event handlers, `href` and `javascript:` / `data:` values; markup that is not a single
  `<svg>` falls back to the kind icon (REQ-UX-001, closes SEARCH-SEC-05). The ESLint rule now forbids every `innerHTML` /
  `outerHTML` assignment. Browser `VERSION` is `1.0.1`.

### Fixed

- `docs/USAGE.md`: the overridable template table, the override procedure, the "application copy always wins" rule and
  the freeze-vs-upgrade trade-off (REQ-TWIG-001); a **Forms** section on the Symfony Form search field and the ban on raw
  form controls in overrides (REQ-TWIG-005), also stated for the demo login in `demo/README.md`.
- Bug report issue template matches the canonical template again (`Database` row, REQ-DOCS-010).
- Spec Kit baseline: `svg.ts` mapped as `FR-TS-006` (inventory 39/39).

## [1.0.0] - 2026-10-16

First release, extracted from the global header search of Symfony Beacon (spec 151) and made generic.

### Added

- **Header search box** (`@NowoSearchKitBundle/search.html.twig`): a magnifier that stretches into a field in place,
  grouped results in a dropdown under it, full-width field and dropdown on phones; overridable blocks
  (`nowo_search_assets`, `nowo_search_root`, `nowo_search_toggle`, `nowo_search_toggle_icon`, `nowo_search_footer`);
  the input is a Symfony Form widget (`SearchFieldType`).
- **Provider SPI** `SearchProviderInterface` (autoconfigured tag `nowo_search_kit.provider`, priority order) with the
  models `SearchQuery`, `SearchGroup`, `SearchItem` (safe URLs only), `SearchBadge`; `SearchEngine` skips empty groups,
  cuts each to `per_group` rows and logs and skips a failing provider.
- **Scope SPI** `SearchScopeResolverInterface` + `SearchScope` (removable chip; default `NullScopeResolver`).
- **Pages group** (`MenuPagesProvider`) from DashboardMenuBundle menus (`menu_pages`): permission-filtered tree,
  breadcrumbs, absolute paths, deduplicated by URL.
- **JSON endpoint** `GET {prefix}?q=&scope=` (`SearchController`, route `nowo_search_kit_search`): signed-in users with
  `role` (JSON 401 / 403 otherwise), per-user rate limiter (`rate_limit`, 429 `{error: rate_limited}`), query cut to `max_length`, invalid scope
  ignored, `Cache-Control: no-store, private`, row icons rendered with UX Icons.
- **Browser module** (TypeScript, IIFE `js/nowo-search.js`, `window.NowoSearch`): Ctrl/⌘ K and `/` shortcuts, ARIA
  combobox + listbox, arrows / Enter / Ctrl-⌘ Enter / Escape / click outside, recents in `localStorage` (text only,
  same-site paths, per-user key), quick actions, `<mark>` highlighting through DOM nodes, debounce + AbortController, lazy dropdown
  stylesheet, `nowo-search:state` and cancelable `nowo-search:navigate` events, Turbo support, Stimulus wrapper
  (`createSearchKitController`).
- **CSS** `css/nowo-search.css` (field, always) and `css/nowo-search-popover.css` (dropdown, lazy) with
  `--nowo-search-*` tokens (light / dark), thin scrollbar, reduced motion.
- **Icons**: `icon_set: ux_icon` (Symfony UX Icons, Tabler defaults, `icons` map) with a built-in SVG fallback, or
  `svg_inline`.
- **Translations** (domain `NowoSearchKitBundle`): en, es, de, fr, it, nl, pt.
- Symfony 8.1 FrankenPHP demo (no database) with Playwright e2e; PHP coverage 100%, TypeScript lines 100%.
