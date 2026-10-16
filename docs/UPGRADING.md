# Upgrading

This guide lists the changes integrators must apply when upgrading between versions.

## Table of contents

- [1.0.1 (2026-10-16)](#101-2026-10-16)
- [1.0.0 (2026-10-16)](#100-2026-10-16)

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
