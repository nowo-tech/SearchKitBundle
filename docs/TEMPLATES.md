# Templates

SearchKitBundle ships **no page**: its UI is one **widget**, the header search (`search.html.twig`), rendered inside
the host's own layout; results come from a JSON endpoint (`/search`) and are drawn by the browser module. For the UiKit
page contract (REQ-UI-003) the widget adopts **theme slots and tokens only**, and UiKit is **optional**
(`nowo-tech/ui-kit-bundle` ^2.2 in `suggest`). References: UiKit `docs/PAGE_CONTRACT.md`, `docs/THEME_SLOTS.md`.

## Table of contents

- [Pages](#pages)
- [Unique pages](#unique-pages)
- [Widget: the header search](#widget-the-header-search)
- [Per-page overrides](#per-page-overrides)
- [Tokens](#tokens)
- [Allowed exceptions](#allowed-exceptions)

## Pages

None: there is no results page (the dropdown is the result list). No contract test (`PageContractTestCase`) applies;
`tests/Functional/SearchPartialTest.php` renders the widget with and without a UiKit stub theme.

## Unique pages

None.

## Widget: the header search

| Part | Element | UiKit theme slot (host classes / attributes only) | Block |
| --- | --- | --- | --- |
| Root | `.nowo-search` (`data-nowo-ui-bundle="NowoSearchKitBundle"`) | — | `nowo_search_root`, `nowo_search_root_attributes` |
| Assets | `<link>` / `<script>` (`assets.*`) | — | `nowo_search_assets` |
| Toggle | `.nowo-search__toggle` | `btn_icon` (classes + attributes) | `nowo_search_toggle`, `nowo_search_toggle_icon` |
| Scope chip | `.nowo-search__scope`, `.nowo-search__scope-remove` | `badge_neutral`, `btn_icon` | `nowo_search_scope` |
| Field | `.nowo-search__input` (Form widget) | `input` | `nowo_search_field` |
| Shortcut hint | `.nowo-search__kbd` | — | `nowo_search_shortcut` |
| Dropdown | `.nowo-search__popover` | `card` (classes + attributes) | `nowo_search_popover` |
| Key hints | `.nowo-search__footer` | `muted` | `nowo_search_footer` |
| Result rows | built by the browser module (`.nowo-search__group`, `__option`, `__badge--<tone>` …) | — (tokens) | — |

**Optional integration.** `Nowo\SearchKitBundle\Twig\SearchKitThemeExtension` gets the UiKit theme service
(`nowo_ui_kit.theme`) when it exists and exposes `nowo_search_kit_ui_class(slot)` / `nowo_search_kit_ui_attrs(slot)`;
without UiKit both return `''` and the markup is the 1.0 markup plus `data-nowo-ui-bundle`. Only the **host** part of a
slot is emitted (`Theme::extraClasses()` / `renderAttributes()` for `NowoSearchKitBundle`): UiKit's semantic and
framework defaults (`form-control`, `btn` …) would break the compact, stretching field. Clear an unsuitable host class
for this bundle only with `nowo_ui_kit.theme.bundles.NowoSearchKitBundle.classes.<slot>: ''`.

## Per-page overrides

`templates/bundles/NowoSearchKitBundle/search.html.twig` with `{% extends '@!NowoSearchKitBundle/search.html.twig' %}`
and one redefined block (e.g. `nowo_search_toggle_icon` for a morphing icon). Do not copy the whole file.

## Tokens

`nowo-search.css` / `nowo-search-popover.css` paint everything from `--nowo-search-*` tokens declared in `:where()`
(zero specificity). The surface, text, muted, border, accent, link and radius defaults read the UiKit `--nowo-ui-*`
tokens first and fall back to the 1.0 light / dark values ([USAGE — Theme tokens](USAGE.md#theme-tokens)).

## Allowed exceptions

None.
