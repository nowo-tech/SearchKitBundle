# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
  - [Added](#added)
  - [Changed](#changed)
- [[1.0.3] - 2026-10-18](#103---2026-10-18)
  - [Fixed](#fixed)
- [[1.0.2] - 2026-10-16](#102---2026-10-16)
  - [Added](#added-1)
  - [Fixed](#fixed-1)
- [[1.0.1] - 2026-10-16](#101---2026-10-16)
  - [Security](#security)
  - [Fixed](#fixed-2)
- [[1.0.0] - 2026-10-16](#100---2026-10-16)
  - [Added](#added-2)

## [Unreleased]

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
