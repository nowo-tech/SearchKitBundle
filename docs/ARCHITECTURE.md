# Architecture

## Layers

| Layer | Namespace / path | Role |
| --- | --- | --- |
| Contracts | `Contract` | `SearchProviderInterface` (one group, tagged `nowo_search_kit.provider`), `SearchScopeResolverInterface` |
| Model | `Model` | `SearchQuery` (text, user, limit, scope), `SearchGroup`, `SearchItem` (safe URL check), `SearchBadge`, `SearchScope` |
| Engine | `Search` | `SearchEngine`: min length, providers in priority order, empty groups skipped, rows cut, failures logged and skipped |
| Built-in providers | `Provider`, `Scope` | `MenuPagesProvider` (DashboardMenuBundle menus, duck-typed), `NullScopeResolver` |
| HTTP | `Controller` | `SearchController`: user + role, per-user limiter, input limits, JSON, no-store |
| Icons | `Icon` | `SearchIcons`: UX Icons with the built-in SVG fallback |
| View | `Twig`, `Form`, `Resources/views` | `SearchKitExtension` (`nowo_search_kit_*` functions), `SearchFieldType`, `search.html.twig` |
| DI | `DependencyInjection` | Flat configuration tree, rate limiter + asset package prepend, Twig paths pass |
| Browser | `Resources/assets/src` | `GlobalSearch` (DOM, keyboard, fetch), recents, highlighting, URL guard, IIFE / Stimulus entries; built file in `Resources/public/js` |
| Styles | `Resources/public/css` | `nowo-search.css` (field, tokens), `nowo-search-popover.css` (dropdown, lazy) |

## Request flow

1. The browser sends `GET /search?q=…&scope=…` (debounced, the previous request aborted).
2. `SearchController` → signed-in user granted `role`? → limiter `nowo_search_kit` (key = SHA-256 of the user
   identifier) → `q` cut to `max_length`, `scope` kept only when it matches `SearchScope::ID_PATTERN`.
3. `SearchEngine::search()` trims the text, returns nothing below `min_length`, then calls each provider with one
   `SearchQuery`.
4. Groups are serialised (`SearchGroup::toArray()`), row icons rendered through `SearchIcons::render()`; the response is
   `no-store, private`.

## Design rules

- The application decides what a user may see; the kit never queries application data and never leaks counts.
- Stateless services only (FrankenPHP worker mode); no per-request caches.
- Text reaches the DOM through text nodes; only trusted SVG from the icon set uses `innerHTML` (lint rule).
- URLs are checked on both sides: `SearchItem` refuses anything but paths and http(s); the browser re-checks before
  rendering and navigating, and stores only same-site paths as recents.
