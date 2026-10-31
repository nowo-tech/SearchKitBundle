# Upgrading

This guide lists the changes integrators must apply when upgrading between versions.

## Table of contents

- [Unreleased](#unreleased)
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
