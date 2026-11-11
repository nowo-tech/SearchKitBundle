# Usage

## Table of contents

- [How it fits together](#how-it-fits-together)
- [Providers](#providers)
  - [Security contract](#security-contract)
  - [`SearchQuery`](#searchquery)
  - [`SearchGroup`, `SearchItem`, `SearchBadge`](#searchgroup-searchitem-searchbadge)
  - [Order of the groups](#order-of-the-groups)
  - [Scoped searches](#scoped-searches)
  - [Full-text search](#full-text-search)
- [Scope resolver](#scope-resolver)
- [Standard Symfony host](#standard-symfony-host)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)
- [Pages group](#pages-group)
- [The header template](#the-header-template)
  - [Blocks](#blocks)
  - [Overriding](#overriding)
  - [Forms](#forms)
  - [Twig functions](#twig-functions)
- [Browser module](#browser-module)
  - [IIFE (default)](#iife-default)
  - [Your bundler](#your-bundler)
  - [Stimulus](#stimulus)
  - [Events](#events)
  - [Morphing toggle icon](#morphing-toggle-icon)
- [UiKit theme (optional)](#uikit-theme-optional)
- [Theme tokens](#theme-tokens)
- [Translations](#translations)
- [JSON endpoint](#json-endpoint)
- [Naming conventions](#naming-conventions)

## How it fits together

1. `@NowoSearchKitBundle/search.html.twig` renders a magnifier (toggle) and a hidden field in your header, plus the
   browser configuration (`data-nowo-search-config`) built by `nowo_search_kit_config()`.
2. `nowo-search.js` mounts it: the toggle, Ctrl/⌘ K or `/` stretch the box into a field; an empty or short query lists
   recents and quick actions.
3. From `min_length` characters, or below it for a query matching
   [`min_length_exempt_patterns`](CONFIGURATION.md#queries-exempt-from-min_length) (debounced), it calls
   `GET /search?q=…&scope=…`.
4. `SearchController` checks the user, the rate limit and the input, then `SearchEngine` asks every tagged
   `SearchProviderInterface` (priority order) and returns the non-empty groups, each cut to `per_group` rows, with the
   row icons rendered by UX Icons.
5. The browser renders the groups (text through DOM nodes, matches in `<mark>`), navigates with the keyboard and
   remembers the picks.

## Providers

```php
namespace App\Search;

use App\Repository\IssueRepository;
use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Model\SearchBadge;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsTaggedItem(priority: 80)]
final readonly class IssueSearchProvider implements SearchProviderInterface
{
    public function __construct(
        private IssueRepository $issues,
        private AccessibleProjects $access,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public function search(SearchQuery $query): ?SearchGroup
    {
        $projectIds = $this->access->idsFor($query->userIdentifier, $query->scope);   // [] for an unknown / forbidden scope
        if ($projectIds === []) {
            return null;
        }
        ['items' => $issues, 'total' => $total] = $this->issues->searchForPalette($projectIds, $query->text, $query->limit);

        return new SearchGroup(
            key: 'issues',
            label: $this->translator->trans('search.group.issues'),
            items: array_map(fn (Issue $issue): SearchItem => new SearchItem(
                title: $issue->getTitle(),
                url: $this->urls->generate('issue_show', ['id' => $issue->getUuid()]),
                subtitle: $issue->getProject()->getName() . ' · ' . $issue->getCulprit(),
                icon: 'tabler:bug',
                badge: new SearchBadge($issue->getLevel(), $issue->getLevel()),
            ), $issues),
            total: $total,
            moreUrl: $query->isScoped() ? $this->urls->generate('issue_index', ['id' => $query->scope, 'q' => $query->text]) : null,
            moreLabel: $this->translator->trans('search.see_all_issues', ['%count%' => $total]),
        );
    }
}
```

With autoconfiguration (default in `config/services.yaml`) nothing else is needed: the interface is tagged
`nowo_search_kit.provider`.

### Security contract

- Return **only rows the current user may open** (same rule as the page the row links to). Never return counts, titles or
  hints of anything else — `total` must count visible matches only.
- `SearchQuery::$scope` is **client input**: an unknown id or one the user may not read yields **no rows** (return null),
  never an exception and never a 403, so the endpoint cannot tell "exists" from "forbidden".
- Use parameters / the query builder (no SQL concatenation with `$query->text`); escape `LIKE` wildcards.
- A provider that throws is logged (provider class and exception class only, never the query) and skipped.

### `SearchQuery`

| Member | Meaning |
| --- | --- |
| `text` | Trimmed query, `min_length` … `max_length` characters (shorter only with `belowMinLength`) |
| `userIdentifier` | The signed-in user's identifier (1.7.0): check permissions against it |
| `user` | **Deprecated** (1.7.0, removed in 2.0): the signed-in Symfony `UserInterface`, or null when the search runs through `SearchEngineInterface::searchFor()` |
| `limit` | Rows per group (`per_group`) |
| `scope` | Scope id (`[A-Za-z0-9._:-]{1,128}`) or null |
| `belowMinLength` | The text is below `min_length` and matched an exempt pattern; only `MinLengthExemptSearchProviderInterface` providers see it. See [CONFIGURATION](CONFIGURATION.md#queries-exempt-from-min_length) |
| `isScoped()` | `scope !== null` |
| `matches(string ...$haystacks)` | Case-insensitive (multibyte) "contains" helper for in-memory filtering |

### `SearchGroup`, `SearchItem`, `SearchBadge`

- `SearchGroup(key, label, items, total = null, moreUrl = null, moreLabel = null)` — `key` (`[a-z0-9_-]`) is also the
  default row kind and the `data-group` attribute; `total` null means "the number of rows"; `moreUrl` adds a
  "See all" row (unsafe URLs are dropped).
- `SearchItem(title, url, subtitle = '', icon = null, badge = null, kind = null)` — `url` must be a path (`/…`, not
  `//host`) or an http(s) URL without control characters or spaces, otherwise the constructor throws; `icon` is a UX Icons name rendered server side (unknown
  names fall back to the kind icon — use a fixed set of names, never user data); `kind` overrides the group key for the icon / recents.
- `SearchBadge(label, tone = 'neutral')` — `tone` becomes `nowo-search__badge--{tone}` (`error`, `fatal`, `warning`,
  `success`, `info` are styled).

### Order of the groups

Tag priority, highest first: `#[AsTaggedItem(priority: 50)]` on the class, or
`tags: [{ name: nowo_search_kit.provider, priority: 50 }]` in YAML. The built-in Pages group defaults to `100`.

### Scoped searches

Decide per provider: hide the group (`return null` when `$query->isScoped()`), or narrow it to the scope. The Pages
group is hidden while scoped (`menu_pages.skip_when_scoped`).

### Full-text search

The kit does not query your database. Use whatever your providers need — `LIKE` with escaped wildcards, MySQL
`MATCH … AGAINST` (see AuditKitBundle's `SqlFulltext` helper), PostgreSQL `tsvector`, Meilisearch… Keep each provider to
one bounded query (`LIMIT $query->limit` plus a capped `COUNT`).

## Scope resolver

Alias `SearchContextScopeResolverInterface` (1.7.0) to start the search inside the current context (shown as a
removable chip). It gets a `SearchContext` VO, not the Symfony `Request` and user:

```php
use Nowo\SearchKitBundle\Contract\SearchContextScopeResolverInterface;
use Nowo\SearchKitBundle\Model\SearchContext;
use Nowo\SearchKitBundle\Model\SearchScope;

final readonly class ProjectScopeResolver implements SearchContextScopeResolverInterface
{
    public function __construct(private AccessibleProjects $access)
    {
    }

    public function resolveFor(SearchContext $context): ?SearchScope
    {
        if (preg_match('#^/projects/([0-9a-f-]{36})(?:/|$)#', $context->path, $m) !== 1) {
            return null;
        }
        $project = $this->access->find($context->userIdentifier, $m[1]);   // null when the user may not read it

        return $project === null ? null : new SearchScope($project->getUuid(), $project->getName());
    }
}
```

```yaml
services:
    Nowo\SearchKitBundle\Contract\SearchContextScopeResolverInterface: '@App\Search\ProjectScopeResolver'
```

| `SearchContext` member | Value |
| --- | --- |
| `userIdentifier` | `UserInterface::getUserIdentifier()` of the signed-in user |
| `path` | `Request::getPathInfo()` of the main request (no query string) |
| `routeName` / `routeParameters` | `_route` / `_route_params` (scalar values only) |
| `locale` | `Request::getLocale()` |
| `roles` / `hasRole()` | `UserInterface::getRoles()`, not expanded by the role hierarchy: do permission checks through your own access port |

The chip never names something the user cannot open: return a scope only after the access check. The id travels as
`scope` and reaches the providers as `SearchQuery::$scope`. The default resolver returns null (no chip). Guests and
calls outside a request get no scope; the resolver is not asked.

**Which resolver is asked.** One resolver decides, in this order:

1. your `SearchContextScopeResolverInterface` alias, when you set one;
2. otherwise the `SearchScopeResolverInterface` alias, through `resolveFor()` when that service implements the new
   interface too;
3. otherwise the deprecated `resolve(Request, UserInterface)` of a resolver that implements only the old interface (it
   keeps working in 1.x and triggers a deprecation; see [UPGRADING](UPGRADING.md#to-170)).

## Standard Symfony host

The zero-config default. Install the bundle, import its routes, include the partial in your header, and write your
providers as services (autoconfigured). Nothing else is needed:

- providers read `SearchQuery::$userIdentifier` (or, until 2.0, the deprecated `SearchQuery::$user`) and can use your
  Doctrine repositories, voters and the `Security` helper directly;
- the bundle's endpoint (`GET {prefix}`) checks the user and `role`, applies the rate limit and runs the providers;
- a scope resolver is optional (alias `SearchContextScopeResolverInterface`);
- the Pages group reads DashboardMenuBundle's menus when `menu_pages` is enabled.

## Using it from a DDD / hexagonal application

SearchKit is a **presentation** component: the header widget, its JSON endpoint and a provider port. It has no
entities, no Doctrine dependency and never queries your database, so your domain does not need to know it exists.
There is no entity to map and no EntityManager: the "dedicated EM / XML mapping" part of the DDD path does not apply.

**Keep the domain free of the bundle**

- Write each `SearchProviderInterface` implementation in your infrastructure (or UI) layer. It is the
  anti-corruption layer: it calls one of your application read ports (query bus, read-model repository, finder) and
  maps the results to `SearchGroup` / `SearchItem`. Domain entities never reach the bundle; only titles, URLs and
  labels do.
- Use `SearchQuery::$userIdentifier` (a string) as your actor id and do the access check in your application layer.
  Do not read the deprecated `SearchQuery::$user`: it is null when the search runs by identifier.
- If the search should start inside a context (project, tenant, workspace), alias
  `SearchContextScopeResolverInterface` to an adapter that resolves it through your access port. It gets a
  `SearchContext` VO (path, route, locale, user identifier, roles); the scope id reaches the providers as
  `SearchQuery::$scope`.
- To run the same fan-out outside the endpoint (CLI, API), inject `SearchEngineInterface` and call
  `searchFor($userIdentifier, $text, $scope)`.
- For your own navigation model in the Pages group, implement `MenuTreeSourceInterface` and set `menu_pages.service`.

None of the ports, events or VOs above carries a Symfony or Doctrine type (REQ-ARCH-001 rule 3). The deprecated
`SearchScopeResolverInterface` and `SearchQuery::$user` are the only exceptions left; they go in 2.0.

**Example adapter**

```php
use App\Application\Project\FindProjectsForUser;        // your application port
use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Model\{SearchGroup, SearchItem, SearchQuery};
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class ProjectSearchProvider implements SearchProviderInterface
{
    public function __construct(private FindProjectsForUser $projects, private UrlGeneratorInterface $urls) {}

    public function search(SearchQuery $query): ?SearchGroup
    {
        $hits = $this->projects->search($query->userIdentifier, $query->text, $query->limit, $query->scope);
        if ($hits->items === []) {
            return null;
        }

        return new SearchGroup('projects', 'Projects', array_map(
            fn ($p): SearchItem => new SearchItem($p->name, $this->urls->generate('app_project', ['id' => $p->id])),
            $hits->items,
        ), $hits->total);
    }
}
```

The test suite boots such a host (`tests/Support/Ddd/DddHostKernel.php`: a plain-PHP read model keyed by user
identifier, providers and a scope resolver on the 1.7 ports only, no services resetter) and runs it for two users in
alternation (`tests/Integration/Ddd/DddHostSearchKernelTest.php`).

**Known limitations**

- The built-in Pages group still reads DashboardMenuBundle by duck typing (`getMenuTree()` / `getHref()`) until that
  bundle implements `MenuTreeSourceInterface`; another navigation model can implement the interface.
- No server-side event is dispatched when a search runs; only the browser events `nowo-search:state` and
  `nowo-search:navigate` exist.

## Pages group

See [CONFIGURATION.md](CONFIGURATION.md#pages-group). The rows link to the menu entries the menu bundle shows to the
user (its permission checkers already filtered the tree); labels match case-insensitively; the breadcrumb starts with
the translated menu `label`.

## The header template

```twig
{{ include('@NowoSearchKitBundle/search.html.twig', {
    nowo_search_actions: [{title: 'Billing', url: path('billing'), subtitle: '', kind: 'action'}],
    nowo_search_id: 'header-search',
    nowo_search_root_attr: {'data-controller': 'nowo-search-kit', class: 'ms-2'},
}) }}
```

`nowo_search_actions` adds quick actions (before the configured ones; rows without a title or with an unsafe URL are
dropped); `nowo_search_id` changes the element ids (default `nowo-search`) when you render two boxes;
`nowo_search_root_attr` (1.0.2) adds attributes to the root element without copying `nowo_search_root`: `class` is
appended to `nowo-search`, values are escaped, `true` renders a bare attribute, `false` / `null` are skipped, invalid
attribute names and the reserved `id`, `data-state`, `data-testid`, `data-nowo-search-config` are ignored. To compute them
in an override, redefine the `nowo_search_root_attributes` block (it prints the extra attributes inside the root tag).

### Blocks

| Block | Content |
| --- | --- |
| `nowo_search_assets` | `<link>` / `<script>` tags (`assets.*`) |
| `nowo_search_root` | The whole component |
| `nowo_search_root_attributes` | Extra attributes of the root tag (from `nowo_search_root_attr`; 1.0.2) |
| `nowo_search_toggle` | The magnifier button |
| `nowo_search_toggle_icon` | The three state icons (search / close / loading), or the `toggle_icon_template` partial |
| `nowo_search_scope` | The scope chip and its remove button (1.1) |
| `nowo_search_field` | The search input (Form widget child loop; 1.1) |
| `nowo_search_shortcut` | The `Ctrl K` hint in the field (1.1) |
| `nowo_search_popover` | The results dropdown (results list + footer; 1.1) |
| `nowo_search_footer` | The key hints under the results |

The script needs these `data-part` elements inside the root: `toggle`, `field`, `input`, `popover`, `results`, `status`
(optional: `box`, `scope`, `scope-remove`, `shortcut`, `mod-key`) and `<template data-nowo-search-icon="…">` per kind.

### Overriding

The bundle ships one template. It can be overridden by creating `templates/bundles/NowoSearchKitBundle/<subpath>` in the
application (REQ-TWIG-001); the application's copy **always wins** over the bundle's (the bundle registers the
`@NowoSearchKitBundle` Twig namespace after the application override directory).

| `<subpath>` | What it renders | Blocks |
| --- | --- | --- |
| `search.html.twig` | The whole header search: assets, toggle, field, dropdown, status and icon templates | `nowo_search_assets`, `nowo_search_root`, `nowo_search_root_attributes`, `nowo_search_toggle`, `nowo_search_toggle_icon`, `nowo_search_scope`, `nowo_search_field`, `nowo_search_shortcut`, `nowo_search_popover`, `nowo_search_footer` |

**Procedure.**

1. Create `templates/bundles/NowoSearchKitBundle/search.html.twig` in the application.
2. Start it with `{% extends '@!NowoSearchKitBundle/search.html.twig' %}` (the `!` prefix always points at the bundle's
   own file) and redefine only the blocks you need.
3. Clear the cache: `php bin/console cache:clear`.

```twig
{% extends '@!NowoSearchKitBundle/search.html.twig' %}

{% block nowo_search_toggle_icon %}
    <morph-icon class="nowo-search__morph" data-search-morph size="14" aria-hidden="true"></morph-icon>
{% endblock %}
```

**Freeze vs upgrade.** Extending the original and redefining single blocks keeps you on the bundle's markup for
everything else, so upgrades bring fixes automatically. A full copy of `search.html.twig` (without `extends`) **freezes**
that file: the application copy keeps hiding the bundle's version — and its fixes — until you delete it or merge the
upstream changes by hand (check [UPGRADING.md](UPGRADING.md) on every upgrade). Prefer configuration (`assets.*`,
`icons`, theme tokens) and the smallest block override. To return to the bundle's template, delete the override and clear
the cache.

### Forms

The search input is a Symfony Form and a **FormKit Type** (`SearchFieldType`, `#[FormKitConfig('search_kit')]`, block prefix `nowo_search`, child `q`; profile `nowo_form_kit.profiles.search_kit`, see [CONFIGURATION](CONFIGURATION.md#formkit-profile)), never a hand-written
control: the template renders it with the `{% for child in form %}{% if not child.rendered %}…{% endif %}{% endfor %}`
child loop (REQ-TWIG-003 / REQ-TWIG-005). Style it through the FormKit profile or the UiKit theme slots. It is never submitted — the browser
module reads it and calls the read-only GET endpoint — so it has no `<form>` wrapper and no CSRF token. Overrides of
`search.html.twig` must keep the form widget and never reintroduce raw `<form>` / `<input>` / `<textarea>` / `<select>`
tags; `make check-no-raw-html-form` fails on them in the bundle and demo templates.

### Twig functions

| Function | Returns |
| --- | --- |
| `nowo_search_kit_config(actions = [])` | Browser configuration, or null when the user may not search |
| `nowo_search_kit_scope()` | `{id, label}` of the current page, or null |
| `nowo_search_kit_form()` | The `FormView` of the field |
| `nowo_search_kit_icons()` | Icon key → SVG markup |
| `nowo_search_kit_toggle_icon_template()` | The configured `toggle_icon_template`, or null |
| `nowo_search_kit_ui_class(slot)` | `' '` + the host classes of a UiKit theme slot (1.1; `''` without UiKit or without host classes) |
| `nowo_search_kit_ui_attrs(slot)` | The host attributes of a UiKit theme slot, escaped (1.1; `''` without UiKit) |

## Browser module

### IIFE (default)

`js/nowo-search.js` exposes `window.NowoSearch` (`mount`, `unmount`, `mountAll`, `GlobalSearch`, helpers) and mounts
every `[data-nowo-search-config]` on DOM ready and after `turbo:load` (unmounting before `turbo:before-render`).

### Your bundler

Turn `assets.script` off and import the sources (TypeScript, no dependency):

```ts
import { mountAll } from '../../vendor/nowo-tech/search-kit-bundle/src/Resources/assets/src/index';
mountAll();
```

Load the CSS yourself as well when `assets.stylesheet` is off:
`vendor/nowo-tech/search-kit-bundle/src/Resources/public/css/nowo-search.css` (always) and `nowo-search-popover.css`
(the dropdown). Keep the dropdown lazy (1.0.2) either with `assets.popover_stylesheet: '/build/…css'` (the box injects
that `<link>` on the first hover / focus) or with the JavaScript option, which wins over the URL:

```ts
mountAll(document, { loadPopoverStyles: () => import('./search-popover.css') });
```

### Stimulus

```ts
import { Controller } from '@hotwired/stimulus';
import { createSearchKitController } from '../../vendor/nowo-tech/search-kit-bundle/src/Resources/assets/src/stimulus';

application.register('nowo-search-kit', createSearchKitController(Controller, {
  loadPopoverStyles: () => import('./search-popover.css'), // optional
  onState: (state, root) => morphToggle(root, state), // optional (1.2): idle / open / loading, initial one included
}));
```

Then add the controller with `nowo_search_root_attr: {'data-controller': 'nowo-search-kit'}` and set
`assets.script: false`.

### Events

| Event (on the root, bubbles) | Detail | Use |
| --- | --- | --- |
| `nowo-search:state` | `{state: 'idle' \| 'open' \| 'loading'}` | Drive a morphing icon, analytics |
| `nowo-search:navigate` (cancelable) | `{url, newTab}` | `preventDefault()` and navigate yourself (e.g. `Turbo.visit(url)`) |

`nowo-search:state` is also dispatched once right after mount with the initial state (1.0.2), so a host can sync its
icon without waiting for the first interaction. The root also carries `data-state` and the class `is-open`.

### Morphing toggle icon

Without extra code the toggle swaps three UX icons (magnifier ↔ ✕ ↔ spinner) by `data-state`. For an animated morph
(e.g. a `<morph-icon>` web component), point `toggle_icon_template` at a partial and listen to `nowo-search:state`:

```yaml
nowo_search_kit:
    toggle_icon_template: search/_toggle_icon.html.twig
```

```twig
{# templates/search/_toggle_icon.html.twig #}
<morph-icon class="nowo-search__morph" data-search-morph size="14" aria-hidden="true"></morph-icon>
```

```ts
document.addEventListener('nowo-search:state', (event) => {
  const { state } = (event as CustomEvent<{ state: 'idle' | 'open' | 'loading' }>).detail;
  syncSearchMorphIcon((event.target as HTMLElement).querySelector('[data-part="toggle"]')!, state, true);
});
```

The partial replaces all three icons, so it shows the states itself (the event above, or CSS on the root's `data-state`).
It gets the block's context (`nowo_search_id`, `nowo_search_icons`, `nowo_search_config`). The button keeps its
`aria-label`: mark the icon `aria-hidden="true"`. Rows of the dropdown are not affected. Redefining the block in a
`templates/bundles/` override still works and wins over the option.

## UiKit theme (optional)

REQ-UI-003 (details: [TEMPLATES.md](TEMPLATES.md)). UiKit is **optional**: without `nowo-tech/ui-kit-bundle` the widget
renders exactly as before. With UiKit ^2.2 installed:

1. the parts also carry the **host** classes / attributes you configured for the UiKit theme slots, read for the bundle
   `NowoSearchKitBundle` — toggle and scope remove `btn_icon`, scope chip `badge_neutral`, input `input`, dropdown
   `card`, footer `muted`. Only host classes: never UiKit's semantic / framework defaults (`form-control`, `btn` …), so
   the compact widget keeps its geometry. A host class meant for admin pages that does not suit the header search is
   cleared per bundle: `nowo_ui_kit.theme.bundles.NowoSearchKitBundle.classes.input: ''`;
2. the `--nowo-search-*` colour and radius tokens default to the UiKit `--nowo-ui-*` tokens (below), so the widget
   follows `nowo_ui_kit.theme.tokens`;
3. the root carries `data-nowo-ui-bundle="NowoSearchKitBundle"` (per-bundle UiKit tokens).

## Theme tokens

Set them on `.nowo-search` — light defaults, dark under `prefers-color-scheme: dark` or `[data-theme="dark"]` /
`[data-bs-theme="dark"]`. Since 1.0.2 the defaults are declared inside `:where()` (zero specificity), so any host rule on
the element wins, whatever the stylesheet order — `.nowo-search { … }` is enough (no `#nowo-search`). Declare the tokens
on the element itself (or a more specific selector such as `.app-header .nowo-search`), not on an ancestor: the element's
own defaults would hide inherited values. Override the dark values under your own dark selector when you change them.

| Token | Role |
| --- | --- |
| `--nowo-search-bg`, `--nowo-search-fg`, `--nowo-search-muted`, `--nowo-search-surface` | Surfaces and text |
| `--nowo-search-border`, `--nowo-search-accent`, `--nowo-search-accent-fg`, `--nowo-search-accent-soft` | Border, focus ring, selection |
| `--nowo-search-mark`, `--nowo-search-shadow` | Match highlight, dropdown shadow |
| `--nowo-search-radius`, `--nowo-search-popover-radius` | Corners |
| `--nowo-search-size`, `--nowo-search-open-width`, `--nowo-search-popover-width` | Closed box, open field, dropdown widths |
| `--nowo-search-font-size`, `--nowo-search-icon-size` | Text and toggle icon size |
| `--nowo-search-z`, `--nowo-search-mobile-top` | Stacking and phone offset from the top |
| `--nowo-search-backdrop` | Dimmed backdrop behind the open field on phones (< 40rem; tap it to close; 1.0.2) |
| `--nowo-search-badge-error`, `-warning`, `-success`, `-info` | Badge tones |

Since 1.1 the light and dark defaults of `--nowo-search-bg`, `-fg`, `-muted`, `-border`, `-surface`, `-accent`,
`-accent-fg` and `-radius` read the UiKit tokens first (`--nowo-ui-surface`, `--nowo-ui-text`, `--nowo-ui-muted`,
`--nowo-ui-border`, `--nowo-ui-surface-muted`, `--nowo-ui-primary`, `--nowo-ui-link`, `--nowo-ui-radius`) and fall back
to the values above; `--nowo-search-accent-soft` is derived from the accent.

```css
.app-header .nowo-search {
  --nowo-search-accent: var(--brand-600);
  --nowo-search-open-width: min(30rem, 45vw);
}
```

Motion: `prefers-reduced-motion: reduce` (and `[data-motion="reduce"]` on an ancestor) disables the stretch and the
dropdown animation.

## Translations

Domain `NowoSearchKitBundle` (en, es, de, fr, it, nl, pt), keys under `search.*` (`label`, `placeholder`, `shortcut`,
`loading`, `empty`, `min_chars`, `error`, `rate_limited`, `results`, `scope_in`, `scope_remove`, `clear_recent`,
`group.{pages,recent,actions}`, `hint.{move,open,close,new_tab}`). Override any of them in
`translations/NowoSearchKitBundle.<locale>.yaml` (REQ-I18N-001) — e.g. a more specific `search.placeholder`. Group
labels of your providers use your own domain.

## JSON endpoint

`GET /search?q=time&scope=<id>` (Accept: application/json, session cookie):

```json
{
  "query": "time",
  "groups": [
    {
      "key": "issues",
      "label": "Issues",
      "total": 7,
      "items": [
        {"title": "Timeout in checkout", "subtitle": "Shop · api", "url": "/issues/1", "kind": "issues", "icon": "<svg …>", "badge": {"label": "error", "tone": "error"}}
      ],
      "more": {"url": "/projects/p/issues?q=time", "label": "See all 7 issues"}
    }
  ]
}
```

Statuses: 200 (also for an inaccessible scope: no groups), 401 `{"error": "unauthenticated"}` for guests, 403
`{"error": "forbidden"}` without `role`, 429 `{"error": "rate_limited"}`. Every response is `Cache-Control: no-store, private` and `X-Robots-Tag: noindex`.

## Naming conventions

PHP classes `Nowo\SearchKitBundle\…`, config root `nowo_search_kit`, route `nowo_search_kit_search`, Twig namespace
`@NowoSearchKitBundle`, Twig functions `nowo_search_kit_*`, translation domain `NowoSearchKitBundle`, CSS classes
`nowo-search__*` and tokens `--nowo-search-*`, DOM hooks `data-part` / `data-nowo-search-*`, browser global
`NowoSearch`, events `nowo-search:*`, provider tag `nowo_search_kit.provider`, rate limiter `nowo_search_kit`.
