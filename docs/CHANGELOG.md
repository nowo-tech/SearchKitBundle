# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.7.0] - 2026-11-11](#170---2026-11-11)
  - [Added](#added)
  - [Deprecated](#deprecated)
  - [Changed](#changed)
- [[1.6.0] - 2026-11-07](#160---2026-11-07)
  - [Added](#added-1)
- [[1.5.0] - 2026-11-07](#150---2026-11-07)
  - [Changed](#changed-1)
- [[1.4.0] - 2026-11-06](#140---2026-11-06)
  - [Added](#added-2)
- [[1.3.1] - 2026-10-31](#131---2026-10-31)
  - [Changed](#changed-2)
- [[1.3.0] - 2026-10-30](#130---2026-10-30)
  - [Changed](#changed-3)
- [[1.2.1] - 2026-10-30](#121---2026-10-30)
  - [Changed](#changed-4)
  - [Fixed](#fixed)
- [[1.2.0] - 2026-10-23](#120---2026-10-23)
  - [Added](#added-3)
- [[1.1.1] - 2026-10-22](#111---2026-10-22)
  - [Changed](#changed-5)
  - [Fixed](#fixed-1)
- [[1.1.0] - 2026-10-18](#110---2026-10-18)
  - [Added](#added-4)
  - [Changed](#changed-6)
- [[1.0.3] - 2026-10-18](#103---2026-10-18)
  - [Fixed](#fixed-2)
- [[1.0.2] - 2026-10-16](#102---2026-10-16)
  - [Added](#added-5)
  - [Fixed](#fixed-3)
- [[1.0.1] - 2026-10-16](#101---2026-10-16)
  - [Security](#security)
  - [Fixed](#fixed-4)
- [[1.0.0] - 2026-10-16](#100---2026-10-16)
  - [Added](#added-6)

## [Unreleased]

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
