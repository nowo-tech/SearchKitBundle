# Upgrading

This guide lists the changes integrators must apply when upgrading between versions.

## Table of contents

- [Unreleased](#unreleased)
- [To 1.12.0](#to-1120)
- [To 1.11.3](#to-1113)
- [To 1.11.2](#to-1112)
- [To 1.11.1](#to-1111)
- [To 1.11.0](#to-1110)
- [To 1.10.1](#to-1101)
- [To 1.10.0](#to-1100)
- [To 1.9.0](#to-190)
- [To 1.8.1](#to-181)
- [To 1.8.0](#to-180)
  - [To 1.8.0](#to-180-1)
- [To 1.7.0](#to-170)
- [To 1.7.0](#to-170-1)
  - [Migration table](#migration-table)
  - [Steps](#steps)
- [To 1.6.0](#to-160)
- [To 1.5.0](#to-150)
- [To 1.4.0](#to-140)
- [To 1.3.1](#to-131)
- [To 1.3.0](#to-130)
- [To 1.2.1](#to-121)
- [To 1.2.0](#to-120)
- [To 1.1.1](#to-111)
- [To 1.1.0](#to-110)
  - [Template contract (1.1)](#template-contract-11)
- [1.0.3 (2026-10-18)](#103-2026-10-18)
- [1.0.2 (2026-10-16)](#102-2026-10-16)
- [1.0.1 (2026-10-16)](#101-2026-10-16)
- [1.0.0 (2026-10-16)](#100-2026-10-16)

## Unreleased

## To 1.12.0

From **1.11.3**:

```bash
composer update nowo-tech/search-kit-bundle
```

## To 1.11.3

From **1.11.2**:

```bash
composer update nowo-tech/search-kit-bundle
```

## To 1.11.2

From **1.11.1**:

```bash
composer update nowo-tech/search-kit-bundle
```

Target: **1.11.2** (patch). Nothing to change. Kit checks that do not apply to your definition (no bound, no archived
state, a 1-row window) now pass instead of being skipped, so the kit runs under `--fail-on-skipped`. An
`--exclude-filter` added for `testContractBoundedCandidatesGiveAnInexactTotal` can be removed.

## To 1.11.1

From **1.11.0**:

```bash
composer update nowo-tech/search-kit-bundle
```

Target: **1.11.1** (patch). Nothing to change. The contract kit's `testContractHiddenRowsBeyondTheWindowChangeNothing`
now seeds one hidden row fewer, so it no longer fails by chance when the definition's order does not follow insertion
(equal timestamps with a random id tiebreak). No runtime change.

## To 1.11.0

From **1.10.1**:

```bash
composer update nowo-tech/search-kit-bundle
```

Target: **1.11.0** (minor). From **1.10.x**:

```bash
composer update nowo-tech/search-kit-bundle
```

Nothing to change in definitions. What is new:

- **Partial candidates.** If your definition bounds its own scan (it reads at most N boards, projects or memberships)
  and the bound can be hit, override `candidateQuery($tenant, $query, SearchCandidateContext $context)` (or
  `filterCandidates()` for a bounded access check) and call `$context->markPartial()` when it is hit. The total is then
  reported as inexact ("N+") instead of exact. SearchKit now calls these two hooks instead of `baseQuery()` and
  `filterVisible()`; their defaults call those, so a definition that overrides neither behaves exactly as before.
  Keep the flag in the context, never in a property (see
  [Bounded candidates](PROVIDERS.md#bounded-candidates-a-partial-total)).
- **Contract kit**: a new test, `testContractBoundedCandidatesGiveAnInexactTotal`, skipped unless you implement the
  optional hook `seedBeyondTheBound()`.
- **Decorators must forward the new hooks.** Any class that wraps another `EntitySearchDefinition` — a host
  decorator (logging, caching, extra access rules) as much as a test helper — must forward `candidateQuery()` and
  `filterCandidates()` to the inner definition, as it forwards the other hooks. A decorator that forwards only
  `baseQuery()` / `filterVisible()` inherits the defaults, which call the decorator's `baseQuery()`, so the inner
  definition's `candidateQuery()` never runs: its bound is skipped and the total is wrongly reported exact. The kit's
  `RenderRecorder` forwards both.
- **DBAL 3**: the kit's `QueryCountingMiddleware` now works on DBAL 3.8+ as well as DBAL 4, so a DBAL 3 host can run
  the contract kit (it was a PHP fatal error in 1.9.0–1.10.1). If you worked around it with your own counting
  middleware, you can switch back. The `doctrine/dbal` suggestion is `^3.8 || ^4.2`.

## To 1.10.1

From **1.10.0**:

```bash
composer update nowo-tech/search-kit-bundle
```

Target: **1.10.1** (patch). From **1.10.0**:

```bash
composer update nowo-tech/search-kit-bundle
```

Nothing to change in definitions. What changes:

- **`exactTotal` is false whenever the candidate window is full**, including when it is full of rows the viewer may
  not see. Before, only visible rows filling the window made it inexact. A group whose definition hides rows only in
  PHP may now show a capped total ("N+") where it used to show an exact one. This only fixes the exact claim: visible
  rows that hidden rows push out of the window are still missing, and that shrink remains a count side channel. Only
  filtering those rows in `baseQuery()` removes it, which also keeps exact totals (see
  [Rows hidden only in PHP](PROVIDERS.md#rows-hidden-only-in-php-still-use-the-candidate-window)). Sensitive groups
  must.
- **`SearchWindow::isExact(array $visible, ?int $fetched = null)`**: pass the number of fetched rows. Calling it without
  that number keeps the 1.9 rule but is **deprecated**, and the argument becomes required in 2.0.
- **Contract kit**: a new test, `testContractHiddenRowsAroundVisibleOnesNeverShrinkAnExactTotal`, and a new optional
  hook, `hiddenRowsFilteredInSql()`. A non-sensitive definition that filters hidden rows in PHP passes, because its
  total is now inexact. If yours filters them in SQL, return `true` so the kit asserts that hidden rows change
  nothing. `testContractHiddenRowsBeyondTheWindowChangeNothing` no longer requires the exactness to stay the same
  unless the hook returns `true`.
- **Kit tightening for sensitive groups.** `hiddenRowsFilteredInSql()` defaults to the definition's `sensitive()`, so
  the kit now requires a sensitive group's hidden rows to change nothing. A sensitive definition that hides rows only
  in PHP fails both window tests until you choose one of two fixes:
  - move the rule into `baseQuery()`;
  - when only PHP or a host event can decide the visibility, override `hiddenRowsFilteredInSql()` to return `false`
    and document in the bundle that the group accepts the candidate-window side channel.

  No released provider runs the kit with `sensitive()` true yet.

## To 1.10.0

From **1.9.0**:

```bash
composer update nowo-tech/search-kit-bundle
```

Target: **1.10.0** (minor, additive). From **1.9.x**:

```bash
composer update nowo-tech/search-kit-bundle
```

Nothing to change: 1.9 definitions and contract tests keep working as they are.

New, optional:

- **`prepareItems()` / `toPreparedItem()`** on `EntitySearchDefinition` — load display data (titles from a resolver,
  names from another table) for the shown rows only, in one statement, instead of per row in `toItem()`. SearchKit calls
  `prepareItems()` once per page with exactly the rows the provider returns (after the access check, the host event
  and the page cut) and once per resolved recent; never when nothing is returned. With `access.filter_all_rows` the
  engine's URL check runs afterwards and may still drop some of them. Store the data in the `SearchItemContext` you
  get and read it back in `toPreparedItem()` — never in a property of the definition (FrankenPHP worker mode). Keep it
  read-only. See [Write a provider](PROVIDERS.md#display-data-for-the-shown-rows-prepareitems).
- **`toItem()` is no longer abstract.** A definition overrides `toItem()` (as in 1.9) or `toPreparedItem()`; with
  neither, the search throws a `LogicException`. A definition that calls `parent::toItem()` (there was none to call)
  is unaffected.
- **Contract kit**:
  - `hasArchivedState()` (default `true`): return `false` when the records have no archived or deleted state; the
    archived checks are then skipped with a message and `seedArchived()` is never called. `seedArchived()` is no
    longer abstract (its default fails the test). A 1.9 test whose `seedArchived()` calls `markTestSkipped()` still
    works, but can now declare `hasArchivedState()` `false` instead, so the hidden-row checks of the mixed tests run.
  - New `testContractNeverShowsArchivedRows`, `testContractPrepareItemsGetsOnlyTheShownRows`,
    `testContractPrepareItemsRunsForResolvedRecents` and `testContractPrepareItemsKeepsTheQueryBudget`; new optional
    hook `prepareItemsBudget()` (default 1 statement per `prepareItems()` call; the default no-op must send none).
    `queryBudget()` counts the statements of `prepareItems()` too: raise it by one if your definition now loads its
    display data there.
  - New `testContractReusedDefinitionRendersLikeAFreshOne`: one definition instance must render like a fresh one
    (alternating viewers). Implement the optional `changeSeededText($id, $text): bool` (update the row, return `true`)
    so it also renders a row whose text changed between two renders.
  - A `seedArchived()` that calls `markTestSkipped()` still skips its tests, with a hint to declare
    `hasArchivedState()` `false` instead (making it fail would break 1.9 test suites in a minor release).
- **`type:` filters**: a provider whose group key is known (every entity provider and every
  `PaginatedSearchProviderInterface`) is no longer called when the viewer's `type:` filters exclude its group;
  before, it ran and its group was dropped afterwards. Results are the same.

## To 1.9.0

From **1.8.1**:

```bash
composer update nowo-tech/search-kit-bundle
```

Target: **1.9.0** (minor, additive). From **1.8.x**:

```bash
composer update nowo-tech/search-kit-bundle
```

Nothing to change: every new key has a default that keeps 1.8 behaviour, and existing `SearchProviderInterface`
services work as before.

New, optional:

- **Entity providers** — a bundle that searches its own records can drop its hand-written provider and extend
  `EntitySearchDefinition` instead ([Write a provider](PROVIDERS.md)). To migrate one:
  1. move the tenant, feature, access and row code into the hooks (`tenant()`, `featureEnabled()`, `baseQuery()`,
     `filterVisible()` or `canView()`, `toItem()`, `seeAllUrl()`);
  2. delete the provider's own LIKE escaping, limit, total, scope checks and "see all" code;
  3. register the definition (autoconfigured, or tag `nowo_search_kit.entity_definition` with the old `priority`)
     and remove the old provider's `nowo_search_kit.provider` tag;
  4. keep the old group key, so `type:` filters, recents and host configuration still match;
  5. run the contract kit (`EntitySearchProviderTestCase`) on MySQL, MariaDB and PostgreSQL.
  An entity group's total is now the **visible** total within the bounded candidates (`maxCandidates()`, default 100):
  exact unless the visible rows fill the window, and never influenced by rows the viewer may not see.
- **Host visibility event** — `SearchKitEvents::ITEM_VISIBILITY` (`SearchItemVisibilityEvent`): one listener in the
  host can deny rows of every entity provider.
- **`nowo_search_kit.groups`** — `groups.<key>.enabled` / `priority` for any provider ([CONFIGURATION](CONFIGURATION.md#groups-190)).
- **Dependencies** — `symfony/event-dispatcher-contracts` ^3.5 is now required (already installed with
  `symfony/framework-bundle`). `doctrine/dbal` ^4.2 and/or `doctrine/orm` ^3.3 are needed only by entity providers.
- A sensitive entity group (`sensitive(): true`) is off while recents are on without `recent.revalidate`.
- Entity providers do **not** run in `SearchEngineInterface::searchFor()` (search by user identifier, without a Symfony
  user): they are not `IdentifierAwareSearchProviderInterface`. A host that calls `searchFor()` gets no entity groups.
- Group keys in `nowo_search_kit.groups` and of entity definitions start with a lower-case letter.

## To 1.8.1

From **1.8.0**:

```bash
composer update nowo-tech/search-kit-bundle
```

## To 1.8.0

From **1.7.0**:

```bash
composer update nowo-tech/search-kit-bundle
```

### To 1.8.0

From **1.7.x**: `composer update nowo-tech/search-kit-bundle`, then `php bin/console assets:install` (new stylesheet
`css/nowo-search-results.css`, rebuilt `js/nowo-search.js`). No configuration change is required: every new key
defaults to the 1.7 behaviour. Behaviour changes to know about:

| Change | Why | What to do |
| --- | --- | --- |
| A group total is capped to the rows the provider returned unless `SearchGroup::$exactTotal` is true | Leak rule of spec 240: a count must not reveal hidden records | Pass `exactTotal: true` (7th argument / named argument) when your total is counted with the same permission filter as the rows |
| `SearchQuery::matches()` folds accents (`é` = `e`, `ß` = `ss`) | Matching in 7 locales | Nothing; it finds more, never less |
| A query starting with `>` lists actions only (quick actions when you have no action provider) | Palette prefix | Set `actions.prefix: ''` to keep `>` as text |
| Groups with the same key are merged (rows deduplicated by URL) | Menu pages + registry pages in one Pages group | Give distinct keys to groups you want apart |
| New routes `{prefix}/results`, `{prefix}/recent`, `{prefix}/filters` come with the route import | Results page, recents, filter values | They answer 404 until you turn the feature on |
| Tag constants of the new ports are named `*_TAG` | A class may implement several ports | — |

To adopt the features, in this order: implement a `SearchAccessCheckerInterface` (nothing new is listed without it),
then add `pages`, action providers, `filters.keys`, `synonyms.builtin_dictionary`, `results_page.enabled` and
`recent.revalidate` as needed ([USAGE](USAGE.md#search-everywhere-180)). Providers gain optional interfaces
(`FilterableSearchProviderInterface`, `PaginatedSearchProviderInterface`, `SearchReferenceResolverInterface`) and
optional `SearchItem::$score` / `$date` ([CONTRACT-240](CONTRACT-240.md)).

## To 1.7.0

From **1.6.0**:

```bash
composer update nowo-tech/search-kit-bundle
```

## To 1.7.0

From **1.6.x**:

```bash
composer update nowo-tech/search-kit-bundle
```

**No action is required.** Providers, a resolver on `SearchScopeResolverInterface`, the endpoint, the partial and the
configuration all behave as in 1.6, and the scope chip is decided by the same resolver as before. 1.7 adds ports
without Symfony types for DDD / hexagonal hosts (REQ-ARCH-001 rule 3) and deprecates the old ones, which go in **2.0**.

One type change matters for static analysis only: `SearchQuery::$user` is now `?UserInterface`. The bundle's endpoint
always fills it, so `$query->user->getUserIdentifier()` still works at run time, but PHPStan or Psalm will report the
nullable access. Switch to `$query->userIdentifier`.

### Migration table

| Deprecated (removed in 2.0) | Replacement (1.7.0) |
| --- | --- |
| `SearchScopeResolverInterface::resolve(Request $request, UserInterface $user)` | `SearchContextScopeResolverInterface::resolveFor(SearchContext $context)` |
| `$request->getPathInfo()` | `$context->path` |
| `$request->attributes->get('_route')` / `'_route_params'` | `$context->routeName` / `$context->routeParameters` (scalars only) |
| `$request->getLocale()` | `$context->locale` |
| `$user->getUserIdentifier()` / `$user->getRoles()` | `$context->userIdentifier` / `$context->roles` (`hasRole()`) |
| `SearchQuery::$user` | `SearchQuery::$userIdentifier` |
| `SearchEngine::search(UserInterface $user, …)` | `SearchEngineInterface::searchFor(string $userIdentifier, …)` (providers opt in with `IdentifierAwareSearchProviderInterface`) |
| A duck-typed Pages group source | `MenuTreeSourceInterface` (optional; duck typing keeps working) |

### Steps

1. **Providers.** Replace `$query->user->getUserIdentifier()` with `$query->userIdentifier`. If a provider needs more
   than the identifier (an entity, a voter), load it from your own user port by identifier. Do not keep reading
   `$query->user`: it is null when the search runs through `SearchEngineInterface::searchFor()`.
2. **Scope resolver.** Implement `SearchContextScopeResolverInterface` and alias it:

   ```yaml
   services:
       Nowo\SearchKitBundle\Contract\SearchContextScopeResolverInterface: '@App\Search\ProjectScopeResolver'
   ```

   Then remove the `SearchScopeResolverInterface` alias. Until you do, a resolver that implements only the old
   interface triggers a deprecation (`nowo-tech/search-kit-bundle 1.7.0`) on each page that renders the box. Keep the
   access check the same: the new port must return a scope only when the user may open it. `roles` are the user
   object's roles without the role hierarchy, so check permissions through your access port rather than with
   `hasRole()` alone.

   While you migrate, one class may implement both interfaces. The bundle then asks it through `resolveFor()` only.
   If both aliases point to different services, the `SearchContextScopeResolverInterface` one wins.
3. **Engine.** Code that injected `SearchEngine` to search from a CLI or an API should inject `SearchEngineInterface`
   and call `searchFor($userIdentifier, $text, $scope)`. Only providers that implement
   `IdentifierAwareSearchProviderInterface` run there, and the others are skipped with a logged notice. Add the marker
   to a provider only after it reads nothing but `$query->userIdentifier`: not `$query->user` (null there; a nullable
   criterion would match `IS NULL` rows) and not `Security::getUser()` (no user in a CLI, the wrong one in a request).
   See [USAGE](USAGE.md#using-it-from-a-ddd--hexagonal-application).
4. **Pages group (optional).** A custom `menu_pages.service` can implement `MenuTreeSourceInterface`.

## To 1.6.0

From **1.5.0**:

```bash
composer update nowo-tech/search-kit-bundle
```

No action needed: `min_length_exempt_patterns` defaults to empty and the rendered box, the endpoint and the providers
behave as before. The configuration gets a new key, and `SearchQuery` a new optional constructor argument.

To let a short identifier search, for example `#7` with `min_length: 3`, do both:

1. Set `min_length_exempt_patterns: ['^#\d+$']`. Patterns are restricted to the subset PHP and JavaScript read alike,
   and an invalid one fails container compilation.
2. Make the provider that resolves it implement `MinLengthExemptSearchProviderInterface`, and run only the exact lookup
   when `$query->belowMinLength` is true. No other provider receives these queries.

Rebuild or reinstall the assets (`assets:install`), since the browser script reads the new configuration. An old script
ignores it and keeps refusing short queries. See
[CONFIGURATION](CONFIGURATION.md#queries-exempt-from-min_length).

## To 1.5.0

From **1.4.0**:

```bash
composer update nowo-tech/search-kit-bundle
```

The close now waits for its fade: the `hidden` state, `dialog.close()` or removal comes up to 110 ms later than before, and a test should wait for the closed state or set the durations to `0ms`. With no transition (`0ms`, or a page without the bundle's CSS) it all happens at once, as before. Durations come from UiKit's `--nowo-ui-motion-*` tokens with fallbacks here, so no UiKit release is needed.

## To 1.4.0

From **1.3.1**:

```bash
composer update nowo-tech/search-kit-bundle
```

Nothing to do: `toggle_icon_template` is new and defaults to `null`, and the widget renders the same bytes without it.

If your `templates/bundles/NowoSearchKitBundle/search.html.twig` only redefines `nowo_search_toggle_icon`, you can delete
it: move the block's content to a partial, then set the option and clear the cache.

```yaml
nowo_search_kit:
    toggle_icon_template: search/_toggle_icon.html.twig
```

An override of the block still wins over the option. A path that does not exist fails the render of every page with the
search box (Twig `LoaderError`), so check a signed-in page after you set it.

## To 1.3.1

From **1.3.0**:

```bash
composer update nowo-tech/search-kit-bundle
```

`nowo-tech/form-kit-bundle` is now required at `^2.17` (was `^2.16`); update both packages together:

```bash
composer update nowo-tech/search-kit-bundle nowo-tech/form-kit-bundle
```

No configuration or template change: the rendered forms are the same. `FormKitFieldTrait` (an internal helper of the bundle form types) is removed; it was never part of the public API.

## To 1.3.0

From **1.2.1**:

```bash
composer update nowo-tech/search-kit-bundle
```

**New required dependency `nowo-tech/form-kit-bundle` (`^2.16`, REQ-TWIG-005 rule 11).** Register
`Nowo\FormKitBundle\NowoFormKitBundle::class => ['all' => true]` in `config/bundles.php` (Symfony Flex does it on
`composer update`); `SearchFieldType` gets its field options from FormKit and fails with an uninitialized
`FormOptionsMerger` without it. Field defaults of the search input now come from the FormKit profile
`nowo_form_kit.profiles.search_kit` ([CONFIGURATION](CONFIGURATION.md#formkit-profile)) instead of a `form_themes`
override; the rendered markup is unchanged.

## To 1.2.1

From **1.2.0**:

```bash
composer update nowo-tech/search-kit-bundle
```

The search endpoint is no longer picked up by the Symfony >= 7.3 default `routing.controllers` import. If your
application never imported the bundle routes and the search box worked anyway (endpoint on `/`), import them explicitly:

```yaml
# config/routes/nowo_search_kit.yaml
nowo_search_kit:
    resource: '@NowoSearchKitBundle/Resources/config/routes.php'
    prefix: /search
    trailing_slash_on_root: false
```

## To 1.2.0

From **1.1.1**: `composer update nowo-tech/search-kit-bundle`. Optional: a host controller that only mounts the box and
listens to `nowo-search:state` can be replaced by `createSearchKitController(Controller, { loadPopoverStyles, onState })`.

## To 1.1.1

From **1.1.0**:

```bash
composer update nowo-tech/search-kit-bundle
```

## To 1.1.0

From **1.0.3**:

```bash
composer update nowo-tech/search-kit-bundle
```

### Template contract (1.1)

No breaking change; UiKit stays optional. Hosts may notice:

1. **With UiKit installed**, the header search follows the UiKit theme: the `--nowo-search-*` colour / radius
   defaults read `--nowo-ui-surface`, `--nowo-ui-text`, `--nowo-ui-muted`, `--nowo-ui-border`,
   `--nowo-ui-surface-muted`, `--nowo-ui-primary`, `--nowo-ui-link` and `--nowo-ui-radius` when the page defines them
   (`nowo-ui.css` or `nowo_ui_kit.theme.tokens`). Hosts that set `--nowo-search-*` on `.nowo-search` keep their values.
2. **Host theme classes** for the slots `btn_icon`, `badge_neutral`, `input`, `card` and `muted` (from
   `nowo_ui_kit.theme.classes` / `.attributes`) are appended to the toggle, scope chip / remove, input, dropdown and
   footer. If a host class meant for admin pages does not suit the header search, clear it for this bundle:
   `nowo_ui_kit.theme.bundles.NowoSearchKitBundle.classes.input: ''`.
3. **Markup.** New blocks `nowo_search_scope`, `nowo_search_field`, `nowo_search_shortcut`, `nowo_search_popover` wrap
   existing markup (no element changed); the root has `data-nowo-ui-bundle="NowoSearchKitBundle"` (reserved in
   `nowo_search_root_attr`). Overrides of `nowo_search_toggle_icon`, `nowo_search_root_attributes`,
   `nowo_search_footer` keep working.

## 1.0.3 (2026-10-18)

No changes required. If you override `nowo_search_root` (or copy `search.html.twig`) and pass an already translated
`placeholder` (or `title`) to `form_widget`, add `translation_domain: false` as the bundle template now does.

## 1.0.2 (2026-10-16)

No breaking changes: run `assets:install` (or your asset build) for the new `js/nowo-search.js` and CSS.

- If you override `nowo_search_root` only to add attributes, you can drop the override and pass
  `nowo_search_root_attr` instead.
- Token overrides written as `#nowo-search { … }` keep working; `.nowo-search { … }` is now enough. Tokens set only on an
  **ancestor** of `.nowo-search` were already hidden by the element's own defaults — move them onto the element.
- `nowo-search:state` listeners receive one extra event right after mount (`idle`, or `open` when the root is already
  open); make the handler idempotent if it was not.
- With `assets.stylesheet: false` the dropdown stylesheet was never loaded lazily; set `assets.popover_stylesheet` or the
  JS `loadPopoverStyles` option (otherwise keep loading `nowo-search-popover.css` yourself, as before).
- Phones get a dimmed backdrop; set `--nowo-search-backdrop: transparent` to keep the previous look.

## 1.0.1 (2026-10-16)

No breaking changes since 1.0.0: run `assets:install` (or your asset build) so the new `js/nowo-search.js` is served.
Row icons now go through `sanitizeSvg()`: an icon whose markup is not a single drawing-only `<svg>` (for example an
`<img>` or `<foreignObject>` from a custom icon source) shows the kind icon instead. Template overrides need no change.

## 1.0.0 (2026-10-16)

First release — nothing to upgrade. Moving an application's own header search to the bundle:

1. `composer require nowo-tech/search-kit-bundle`, register the bundle, import the routes (prefix of your old endpoint,
   `trailing_slash_on_root: false`) and run `assets:install`.
2. Turn each group of the old search service into a `SearchProviderInterface` (same permission checks; an unknown
   scope returns null).
3. Replace the project-scope Twig helper with a `SearchScopeResolverInterface`.
4. Replace the header partial with `{{ include('@NowoSearchKitBundle/search.html.twig', {nowo_search_actions: …}) }}`
   and remove the old controller, JS controller, styles and `search.*` translations (keep overrides in
   `translations/NowoSearchKitBundle.<locale>.yaml`).
5. Move the old rate limiter values to `nowo_search_kit.rate_limit`.
