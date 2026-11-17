# Feature specification: Search everywhere (Beacon spec 240)

**Package**: `nowo-tech/search-kit-bundle`
**Target**: 1.8.0 (minor, no BC break)
**Status**: Implemented (branch `feat/240-search-everywhere`)
**Approved**: owner, 2026-11-17 (D-20261117-11)
**Source request**: Beacon session, 2026-11-17 — "todo mirando y respetando mis permisos y accesos"
**Inventory**: [`../001-baseline/code-inventory.md`](../001-baseline/code-inventory.md)
**Ports for other bundles**: [`../../docs/CONTRACT-240.md`](../../docs/CONTRACT-240.md)

## Summary

Every page, tab and settings section findable by keywords in 7 locales, a command palette, typed filters, synonyms,
relevance + recency ranking, a full results page and safe recents — always filtered by the viewer's permissions, deny
by default. Everything is generic (any host with menus, settings and content); a host only registers an access
checker, providers and configuration.

## Owner rule (non-negotiable)

Everything respects the viewer's permissions in context, deny by default. Nothing may leak through counts, snippets,
titles, typed filters, suggestions or recents:

- an unauthorised result does not exist for that viewer (filtered before counting, ranking and snippets);
- a provider without an access checker yields nothing;
- recents store references only and are re-checked on every display;
- typed filters cannot probe for existence (unknown / unauthorised value = empty result);
- no obviously distinguishable work difference between "exists but hidden" and "does not exist"; remainder documented
  (docs/SECURITY.md, "Anti-leak model").

## User scenarios

### US-240-01 — Find a page by a keyword in any locale (P1)

**Given** registry entries with per-locale keywords and an access checker,
**when** a viewer types a keyword (in any of the 7 locales, or a synonym of it),
**then** the Pages group lists the entries the checker allows, the UI locale ranked first, keywords never shown;
entries the checker does not allow are never matched or counted.

### US-240-02 — Palette actions (P1)

**Given** action providers, **when** the viewer opens the box (empty query) or types `>` + text,
**then** the box lists the context-aware actions the checker allows (GET links), on top of the results or alone.

### US-240-03 — Typed filters (P1)

**Given** configured filter keys, **when** the viewer types `key:value` tokens,
**then** only providers that apply every key run; chips show the filters; values are suggested from visible values
only; an unknown and a forbidden value give identical answers.

### US-240-04 — Better matching (P2)

Case and accent folding, optional fuzzy matching with bounded cost, synonym expansion (built-in concept groups across
7 locales, host providers), cross-locale with the UI locale first.

### US-240-05 — Ranking (P2)

Rows with a score or a date are sorted by relevance + recency inside their group; *Top results* on the results page.

### US-240-06 — Results page (P1)

`/search/results?q=` with tabs per group (returned-row counts), filter chips, paging for paginated providers, host
layout, UiKit components; *See all results* ends the dropdown.

### US-240-07 — Safe recents (P1)

References only in the browser; revalidated on every display (POST, CSRF, rate limit) through the checker and the
resolvers; dropped references disappear from storage.

### US-240-08 — Ports for the other bundles (P1)

Stable ports and a written contract for ManualKit, DashboardMenu, ChatKit, UptimeMonitor, ApiStudio, TaskBoard, Wiki.

## Functional requirements

| ID | Requirement |
| --- | --- |
| FR-240-ACCESS-001 | `SearchAccessCheckerInterface::decide(SearchAccessRequest): ?bool`; allowed only with one `true` and no `false`; none / only `null` / exception → denied. |
| FR-240-PAGES-001 | `nowo_search_kit.pages` entries `{id, label, translation_domain, route, route_parameters, trail, icon, keywords, scope, scope_parameter, attribute, subject}`; access checked before matching; scope rule; per-locale keywords (`page_registry.locales`), labels of other locales as keywords; merged with menu pages (dedupe by URL); bounded by `max_candidates`. |
| FR-240-PAGES-002 | `SearchPageSourceInterface::pages(SearchQuery)` runtime entries, same rules; a failing source is skipped. |
| FR-240-PAGES-003 | Menu keywords: `menu_pages.keywords` (route → key), `getSearchKeywords()`; `check_access`; `ranked`. |
| FR-240-ACTIONS-001 | `SearchActionProviderInterface` → `SearchAction {label, url, keywords, icon, shortcut, context}`; GET links; access-checked; Actions group first; `actions.prefix` (`>`); empty-query list from providers, `quick_actions` fallback. |
| FR-240-FILTER-001 | Parser for configured keys (`key:value`, quoted, `-key:`), ≤ `filters.max`; `FilterableSearchProviderInterface::supportedFilters()`; unsupported key → provider skipped; filters-only query → filter-aware providers only; engine `type:`. |
| FR-240-FILTER-002 | `SearchFilterValuesProviderInterface` + `GET {prefix}/filters`: visible values only, identical empty answer otherwise. |
| FR-240-MATCH-001 | `SearchQuery::matches()` / `score()`: folding, tiers, fuzzy (typed text only), bounded cost. |
| FR-240-SYN-001 | `SearchSynonymProviderInterface::synonymsOf(word, locale)`; expansion UI locale first, words replaced in place, ≤ `max_terms`; built-in concept groups (7 locales). |
| FR-240-RANK-001 | `SearchItem::$score` / `$date`; stable sort inside groups only when ranked; top results. |
| FR-240-LEAK-001 | Total ≤ returned rows unless `SearchGroup::$exactTotal`; `access.filter_all_rows`; failing provider drops its group, never an error row. |
| FR-240-RESULTS-001 | `nowo_search_kit_results`: tabs, counts of returned rows, top results, chips, paging (`PaginatedSearchProviderInterface`), configurable template / layout, UiKit partials, no script. |
| FR-240-RECENT-001 | `recent.revalidate`: references only; `POST {prefix}/recent` with CSRF header, rate limit, body ≤ 32 KB, ≤ `recent.max` refs; checker + `SearchReferenceResolverInterface`. |
| FR-240-TS-001 | Browser: `>` bypasses `minLength`, `from` path, chips, value suggestions (fallback to search), see-all row, revalidated recents. |
| FR-240-COST-001 | Bounded matching cost (audit SP-M1): terms and texts folded once, ≤ 32 keywords per entry and locale, ≤ 64 other-locale keywords after deduplication, substring pre-filter, exact tiers before fuzzy, early stop, ≤ 100,000 comparisons per registry search (fuzzy = 8); regression test 500 × 30 × 25 < 200 ms. |
| FR-240-CONTEXT-001 | The client-supplied `from` context reaches action providers only when the viewer may open that page (audit SP-L1). |
| FR-240-I18N-001 | Every new string in en es it fr pt de nl. |
| FR-240-BC-001 | Defaults keep a 1.7 host unchanged (documented exceptions in UPGRADING: total cap, accent folding, `>` prefix, merge by key). |
| FR-240-WORKER-001 | Stateless services, Igor clean, kept-alive kernel test with alternating viewers; standard and DDD hosts (identifier-aware providers). |

## Decisions (most privacy-preserving option where the request left a choice)

1. Totals are capped to returned rows by default; a provider opts in to an exact total (`exactTotal`).
2. Several access checkers: unanimous — one `false` vetoes, at least one `true` needed.
3. Actions are access-checked too (also external URLs: a checker must allow them).
4. Recents with revalidation store no titles; a reference needs both the checker and a resolver; until the server
   answers nothing is shown; a 429 / network error shows nothing but keeps the references.
5. Filters-only queries reach only filter-aware providers; `type:` is engine-side and runs the same providers for
   hidden and unknown keys.
6. Fuzzy matching never applies to synonyms (precision), and is off by default.
7. Results page tabs count returned rows, never totals; "next" from a full page, never a total.
8. Global registry entries (`scope: none`) are not listed while the search is scoped; scoped entries only while
   scoped.
9. New features are opt-in (`results_page`, `recent.revalidate`, `filters.keys`, `builtin_dictionary`,
   `filter_all_rows`) so a 1.7 host is unchanged; synonym expansion is on but inert without providers.
10. The results page lives at `{prefix}/results` (the JSON endpoint keeps `{prefix}`).

## Success criteria

- Permission matrix (alice / bob / admin) and anti-leak functional tests green: counts, snippets, recents, filter
  probing (byte-identical bodies), kept-alive kernel.
- PHP line coverage 100%, TypeScript ≥ 90%; PHPStan, Rector, Igor, cs-check clean; Playwright e2e in the demo green.
