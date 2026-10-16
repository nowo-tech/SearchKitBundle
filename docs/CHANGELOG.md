# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.0.0] - 2026-10-16](#100---2026-10-16)
  - [Added](#added)

## [Unreleased]

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
