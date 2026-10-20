# Search Kit Bundle — baseline code inventory

**Package**: `nowo-tech/search-kit-bundle`  
**Last audited**: 2026-10-20  
**Production units**: **40/40**  
**Audit command**: `find src -type f \( -name '*.php' -o -name '*.twig' -o -name '*.yaml' -o -name '*.ts' -o -name '*.css' \) -not -path 'src/Resources/assets/tests/*' | wc -l`

## Scope notes

- Vitest files under `src/Resources/assets/tests/` are **excluded** (test code, not shipped behaviour).
- `src/Resources/public/js/nowo-search.js` is the Vite **build output** of `iife.ts` (`FR-BUILD-001`), not a separate unit.
- `src/Resources/assets/README.md` is documentation; `demo/` and `.symfony/` (Flex recipe, see `docs/INSTALLATION.md`) are out of inventory scope.

## Coverage summary

| Category | Mapped |
| --- | ---: |
| Bundle, configuration & DI | 6 |
| Contracts & model | 8 |
| Engine & providers | 3 |
| HTTP | 1 |
| Twig, markup & theme | 4 |
| Stylesheets | 2 |
| Browser (TypeScript) | 9 |
| Translations | 7 |
| **Total** | **40** |


## Bundle, configuration & DI

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/DependencyInjection/Compiler/TwigPathsPass.php` | US-01, US-07 | FR-BUNDLE-001 |
| `src/DependencyInjection/Configuration.php` | US-01 … US-08 | FR-CFG-001 |
| `src/DependencyInjection/NowoSearchKitExtension.php` | US-01, US-06, US-08 | FR-CFG-002 |
| `src/NowoSearchKitBundle.php` | US-01 | FR-BUNDLE-001 |
| `src/Resources/config/routes.php` | US-01 | FR-ROUTE-001 |
| `src/Resources/config/services.php` | US-01, US-02 | FR-DI-001 |

## Contracts & model

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Contract/SearchProviderInterface.php` | US-02 | FR-SPI-001 |
| `src/Contract/SearchScopeResolverInterface.php` | US-04 | FR-SPI-002 |
| `src/Model/SearchBadge.php` | US-02 | FR-MODEL-001 |
| `src/Model/SearchGroup.php` | US-02 | FR-MODEL-001 |
| `src/Model/SearchItem.php` | US-02, US-08 | FR-MODEL-001 |
| `src/Model/SearchQuery.php` | US-02, US-04 | FR-MODEL-001 |
| `src/Model/SearchScope.php` | US-04 | FR-MODEL-001 |
| `src/Icon/SearchIcons.php` | US-07 | FR-ICON-001 |

## Engine & providers

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Search/SearchEngine.php` | US-02, US-08 | FR-ENGINE-001 |
| `src/Provider/MenuPagesProvider.php` | US-06 | FR-PAGES-001 |
| `src/Scope/NullScopeResolver.php` | US-04 | FR-SPI-002 |

## HTTP

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Controller/SearchController.php` | US-02, US-04, US-08 | FR-API-001 |

## Twig, markup & theme

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Twig/SearchKitExtension.php` | US-01, US-04, US-05 | FR-TWIG-001 |
| `src/Twig/SearchKitThemeExtension.php` | US-01 | FR-TWIG-004 |
| `src/Form/SearchFieldType.php` | US-01 | FR-TWIG-003 |
| `src/Resources/views/search.html.twig` | US-01, US-03, US-04, US-07 | FR-TWIG-002 |

## Stylesheets

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Resources/public/css/nowo-search.css` | US-03, US-07 | FR-THEME-001 |
| `src/Resources/public/css/nowo-search-popover.css` | US-03, US-07 | FR-THEME-001 |

## Browser (TypeScript)

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Resources/assets/src/global-search.ts` | US-03 … US-05, US-07, US-08 | FR-TS-002 |
| `src/Resources/assets/src/highlight.ts` | US-03, US-08 | FR-TS-003 |
| `src/Resources/assets/src/iife.ts` | US-01 | FR-TS-001, FR-BUILD-001 |
| `src/Resources/assets/src/index.ts` | US-01, US-07 | FR-TS-001 |
| `src/Resources/assets/src/recent.ts` | US-05 | FR-TS-003 |
| `src/Resources/assets/src/stimulus.ts` | US-07 | FR-TS-004 |
| `src/Resources/assets/src/svg.ts` | US-02, US-08 | FR-TS-006 |
| `src/Resources/assets/src/types.ts` | US-02, US-07 | FR-TS-005 |
| `src/Resources/assets/src/url.ts` | US-08 | FR-TS-003 |

## Translations

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Resources/translations/NowoSearchKitBundle.de.yaml` | US-01 | FR-I18N-001 |
| `src/Resources/translations/NowoSearchKitBundle.en.yaml` | US-01 | FR-I18N-001 |
| `src/Resources/translations/NowoSearchKitBundle.es.yaml` | US-01 | FR-I18N-001 |
| `src/Resources/translations/NowoSearchKitBundle.fr.yaml` | US-01 | FR-I18N-001 |
| `src/Resources/translations/NowoSearchKitBundle.it.yaml` | US-01 | FR-I18N-001 |
| `src/Resources/translations/NowoSearchKitBundle.nl.yaml` | US-01 | FR-I18N-001 |
| `src/Resources/translations/NowoSearchKitBundle.pt.yaml` | US-01 | FR-I18N-001 |
