# Feature specification: Search Kit Bundle (baseline)

**Package**: `nowo-tech/search-kit-bundle`
**Status**: Active (1.0.2)
**Last reviewed**: 2026-10-16
**Inventory**: [`code-inventory.md`](code-inventory.md) — 100% of the production units under `src/`.

## Summary

A global header search (command palette) for Symfony applications, extracted from Symfony Beacon (spec 151) and made
generic. Installing the bundle gives integrators:

- **Configuration** under `nowo_search_kit` (flat tree): role, length limits, rows per group, debounce, shortcuts, rate
  limit, recents, quick actions, assets, icons and the built-in Pages group.
- **SPIs**: `SearchProviderInterface` (one group per provider, priority order, permission-aware by contract) and
  `SearchScopeResolverInterface` (scope chip).
- **A JSON endpoint** (`GET {prefix}?q=&scope=`) for signed-in users, rate limited per user, never cached.
- **A header partial** (`@NowoSearchKitBundle/search.html.twig`) with overridable blocks, a Symfony Form field and UX
  Icons (built-in SVG fallback).
- **A TypeScript module** (IIFE `nowo-search.js`, ES sources, Stimulus wrapper): stretchable field, keyboard navigation,
  ARIA combobox + listbox, recents, safe highlighting, debounce + abort, events.
- **CSS** with `--nowo-search-*` tokens (light / dark, reduced motion) and **7 locales**.

## User scenarios

### US-01 — Install and render (P1)

**Given** a Symfony 7.4 / 8.x application with SecurityBundle and TwigBundle,
**when** the integrator installs the bundle, imports the routes, runs `assets:install` and includes
`@NowoSearchKitBundle/search.html.twig` in the signed-in header,
**then** signed-in users granted `role` see a magnifier; guests and other users see nothing.

### US-02 — Provider groups (P1)

**Given** services implementing `SearchProviderInterface`,
**when** a user types at least `min_length` characters,
**then** the endpoint returns the non-empty groups in priority order, each cut to `per_group` rows with its total and
optional "See all" link; a failing provider is logged and skipped; rows the provider does not return never appear.

### US-03 — Keyboard and pointer (P1)

**When** the user presses the magnifier, Ctrl/⌘ K anywhere or `/` outside a field, **then** the box stretches into a
focused field; ↑ ↓ move across groups (wrapping), Enter opens, Ctrl/⌘ Enter opens a new tab, Esc or a click outside
folds it back; on phones the field and the dropdown span the screen.

### US-04 — Scope (P2)

**Given** a `SearchScopeResolverInterface` returning a scope for the current page, **then** a chip shows it and the
requests carry `scope`; the chip's button or Backspace in an empty field removes it; an invalid scope id is ignored and
an inaccessible one yields no rows (never 403).

### US-05 — Recents and quick actions (P2)

**When** the query is shorter than `min_length`, **then** the box lists the viewer's recent picks (per browser, text
and same-site paths only, clearable) and the quick actions (configured, role-filtered, plus the ones the host passes).

### US-06 — Pages group (P3)

**Given** DashboardMenuBundle (or any compatible menu service) and `menu_pages.menus`, **then** a Pages group lists the
visible menu entries whose label contains the query, with a breadcrumb, deduplicated by URL, hidden while scoped.

### US-07 — Customisation (P3)

**Then** integrators can override the template blocks, theme the box with `--nowo-search-*` tokens, map icons, listen
to `nowo-search:state` (morphing icon) and cancel `nowo-search:navigate` (own navigation, e.g. Turbo), or mount the
module from their own bundler / Stimulus.

### US-08 — Abuse and leaks (P1)

**Then** the endpoint enforces the per-user rate limit (429 `{error: rate_limited}`), cuts the query to `max_length`,
answers `Cache-Control: no-store, private`, never logs the query, and the browser only renders text through DOM nodes and
only navigates to same-site paths or http(s) URLs.

## Functional requirements

### Bundle, configuration and DI

- **FR-BUNDLE-001** — `NowoSearchKitBundle` registers the extension and `TwigPathsPass` (`@NowoSearchKitBundle`,
  application overrides in `templates/bundles/NowoSearchKitBundle/` first, `@!NowoSearchKitBundle` for the originals).
- **FR-CFG-001** — `Configuration` defines the flat `nowo_search_kit` tree with safe defaults and validation (quick
  action needs exactly one of `route` / `url`; storage key characters; numeric ranges). Since 1.0.2: `assets.popover_stylesheet` (`null` / `false` / safe path or URL).
- **FR-CFG-002** — `NowoSearchKitExtension` prepends the `nowo_search_kit` asset package and the `nowo_search_kit`
  rate limiter (sliding / fixed window or token bucket), sets the parameters, autoconfigures providers with the tag
  `nowo_search_kit.provider` and registers `MenuPagesProvider` when `menu_pages` is enabled (auto: menus configured and
  the service class exists).
- **FR-DI-001** — `services.php` wires the engine (tagged iterator, logger), controller (role, max length, limiter),
  icons (UX Icons renderer when installed), the form type, the Twig extension and the null scope resolver alias.
- **FR-ROUTE-001** — `routes.php` imports the attribute routes for the host to prefix.

### Model and contracts

- **FR-SPI-001** — `SearchProviderInterface::search(SearchQuery): ?SearchGroup` with the documented security contract.
- **FR-SPI-002** — `SearchScopeResolverInterface::resolve(Request, UserInterface): ?SearchScope`; `NullScopeResolver`
  is the default.
- **FR-MODEL-001** — `SearchQuery` (text, user, limit, scope, `isScoped()`, `matches()`), `SearchGroup` (`limit()`,
  `toArray()` with sanitised key and safe "See all"), `SearchItem` (`isSafeUrl()`: paths or http(s), no control
  characters or spaces; rejects unsafe URLs), `SearchBadge`
  (CSS-safe tone), `SearchScope` (id pattern).

### Engine and providers

- **FR-ENGINE-001** — `SearchEngine` trims, enforces `min_length`, runs the providers in priority order, drops null /
  empty groups, cuts rows to `per_group`, logs (class names only) and skips failing providers.
- **FR-PAGES-001** — `MenuPagesProvider` walks the configured menu codes through a duck-typed menu service
  (`getMenuTree`, `getHref`), matches labels case-insensitively, builds breadcrumbs, uses absolute paths, skips unsafe or
  empty links, deduplicates by URL, and returns nothing while scoped (`skip_when_scoped`).

### HTTP

- **FR-API-001** — `SearchController` (`GET`, route `nowo_search_kit_search`): signed-in user granted `role`
  (JSON 401 `unauthenticated` / 403 `forbidden` otherwise), per-user limiter keyed by a SHA-256 of the identifier (429 JSON), `q` cut to
  `max_length`, `scope` kept only when it matches the id pattern, groups serialised with UX icons, headers
  `Cache-Control: no-store, private` and `X-Robots-Tag: noindex`.

### Twig, markup and theme

- **FR-TWIG-001** — `SearchKitExtension`: `nowo_search_kit_config(actions)` (null for users who may not search;
  endpoint, limits, shortcuts, scope, host + configured quick actions with safe URLs and role filter, recents (per-user
  HMAC storage key), assets,
  lazy stylesheet URL, labels), `nowo_search_kit_scope()`, `nowo_search_kit_form()`, `nowo_search_kit_icons()`. `popoverCss` honours `assets.popover_stylesheet` (1.0.2).
- **FR-TWIG-002** — `search.html.twig`: asset tags, root with `data-nowo-search-config`, toggle with three state icons,
  field with scope chip and Form widget (combobox attributes), shortcut hint, dropdown (listbox + key hints), live
  status, kind icon `<template>`s; blocks `nowo_search_assets`, `nowo_search_root`, `nowo_search_toggle`,
  `nowo_search_toggle_icon`, `nowo_search_footer`. Since 1.0.2: `nowo_search_root_attr` / block `nowo_search_root_attributes` add escaped host attributes to the root (class appended; reserved and invalid names ignored).
- **FR-TWIG-003** — `SearchFieldType`: one `q` search field, no CSRF (never submitted), translation domain
  `NowoSearchKitBundle`.
- **FR-ICON-001** — `SearchIcons`: icon keys rendered through UX Icons (`icon_set: ux_icon`) with built-in SVG fallback;
  row icons by name (null when unavailable).
- **FR-THEME-001** — `nowo-search.css` (always): tokens (light, dark by media query or theme attribute), stretch,
  state icons, scope chip, phone layout, reduced motion. `nowo-search-popover.css` (lazy): dropdown, groups, options,
  highlight, badges, thin scrollbar, footer, phone layout. Since 1.0.2: default tokens inside `:where()` (zero specificity); phone backdrop `--nowo-search-backdrop`.
- **FR-I18N-001** — `NowoSearchKitBundle` translations in 7 locales (en, es, fr, de, it, pt, nl) with key parity.

### Browser (TypeScript)

- **FR-TS-001** — `index.ts` / `iife.ts`: `mount` (idempotent, null for invalid config or missing parts), `unmount`,
  `mountAll`, `autoStart` (DOM ready, Turbo load / before-render), `VERSION`, `CSS_TOKEN_PREFIX`; IIFE global
  `NowoSearch`. `mount` / `mountAll` accept `SearchOptions` (1.0.2).
- **FR-TS-002** — `global-search.ts`: open / close / toggle, shortcuts, outside click, debounce + AbortController,
  fetch with 429 / error / abort handling, grouped rendering (ARIA group / option, `aria-activedescendant`, live count),
  arrows / Enter / Ctrl-⌘ Enter / Escape / Backspace scope removal, hover selection, recents and quick actions, lazy
  stylesheet, `nowo-search:state` and cancelable `nowo-search:navigate` events. Since 1.0.2: initial `nowo-search:state` after mount, `loadPopoverStyles` option, tap on the phone backdrop closes.
- **FR-TS-003** — `recent.ts`, `highlight.ts`, `url.ts`: storage helpers (try/catch, text only, same-site paths),
  `<mark>` highlighting through DOM nodes, `isMac`, URL guards.
- **FR-TS-004** — `stimulus.ts`: `createSearchKitController(Controller)` and `STIMULUS_IDENTIFIER`.
- **FR-TS-005** — `types.ts`: shared shapes.
- **FR-TS-006** — `svg.ts`: `sanitizeSvg` turns a row icon's server SVG into a detached element (inert parse, single
  `<svg>` root, drawing elements only, no event handler / `href` / `javascript:` or `data:` value); anything else falls
  back to the kind icon. No HTML string is ever assigned to the page.
- **FR-BUILD-001** — `vite build` produces `src/Resources/public/js/nowo-search.js` (IIFE, `NowoSearch`).

## Key entities

| Entity | Where | Notes |
| --- | --- | --- |
| **Query** | `Model/SearchQuery` | What providers receive: trimmed text, user, limit, optional scope id (client input). |
| **Group** | `Model/SearchGroup` | Key (default row kind, `data-group`), label, rows, total, optional "See all". |
| **Row** | `Model/SearchItem` | Title, safe URL, subtitle, UX icon name, badge, kind. |
| **Scope** | `Model/SearchScope` | Id (`[A-Za-z0-9._:-]{1,128}`) and label of the chip. |
| **Recent** | browser `localStorage` | `{title, subtitle, url, kind}` — no HTML, same-site paths only. |

## Assumptions

- The host provides authentication (SecurityBundle) and decides permissions in its providers and scope resolver.
- The rate limiter storage is shared across workers in production (e.g. Redis).
- Icons come from the host's UX icon set (or the built-in SVG).

## Success criteria

- **SC-01** — `code-inventory.md` maps **39/39** production units (see its audit command).
- **SC-02** — PHPUnit: 100% line coverage of `src/`.
- **SC-03** — Vitest: 100% lines / statements / functions of `Resources/assets/src` (branches ≥ 95%).
- **SC-04** — PHPStan level 8 (with the FrankenPHP rulesets), Rector and PHP-CS-Fixer report nothing; Igor passes.
- **SC-05** — Translation key parity across the 7 locales.
- **SC-06** — The Symfony 8.1 demo boots (healthcheck), its PHPUnit and Playwright suites pass.

## Non-goals

- Querying or indexing application data, deciding permissions (providers do).
- A full results page.
- Multiple configuration profiles (one search box per application).

## Validation

```bash
make release-check          # hygiene, assets, QA, Igor, 100% coverage, Vitest, translations, demos
make qa                     # cs-check, PHPStan, PHPUnit, Vitest
make test-coverage          # PHPUnit with coverage
make test-ts                # Vitest with coverage
make validate-translations  # key parity across the 7 locales
make -C demo/symfony8 test-e2e
# Inventory count (must equal SC-01):
find src -type f \( -name '*.php' -o -name '*.twig' -o -name '*.yaml' -o -name '*.ts' -o -name '*.css' \) -not -path 'src/Resources/assets/tests/*' | wc -l
```

## Links

- [Spec-driven development](../../docs/SPEC-DRIVEN-DEVELOPMENT.md) — user stories, scope, `REQ-*` anchors
- [Usage](../../docs/USAGE.md) · [Configuration](../../docs/CONFIGURATION.md) · [Security](../../docs/SECURITY.md)
