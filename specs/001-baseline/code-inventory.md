# Search Kit Bundle — baseline code inventory

**Package**: `nowo-tech/search-kit-bundle`  
**Last audited**: 2026-11-17 (spec 240, 1.8.0)  
**Production units**: **90/90**  
**Audit command**: `find src -type f \( -name '*.php' -o -name '*.twig' -o -name '*.yaml' -o -name '*.ts' -o -name '*.css' \) -not -path 'src/Resources/assets/tests/*' | wc -l`

## Scope notes

- Vitest files under `src/Resources/assets/tests/` are **excluded** (test code, not shipped behaviour).
- `src/Resources/public/js/nowo-search.js` is the Vite **build output** of `iife.ts` (`FR-BUILD-001`), not a separate unit.
- `src/Resources/assets/README.md` is documentation; `demo/` and `.symfony/` (Flex recipe, see `docs/INSTALLATION.md`) are out of inventory scope.

## Coverage summary

| Category | Mapped |
| --- | ---: |
| Bundle, configuration & DI | 7 |
| Contracts & model | 30 |
| Engine & providers | 18 |
| HTTP | 5 |
| Twig, markup & theme | 8 |
| Stylesheets | 3 |
| Browser (TypeScript) | 12 |
| Translations | 7 |
| **Total** | **90** |


## Bundle, configuration & DI

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/DependencyInjection/Compiler/TwigPathsPass.php` | US-01, US-07 | FR-BUNDLE-001 |
| `src/DependencyInjection/Configuration.php` | US-01 … US-08 | FR-CFG-001 |
| `src/DependencyInjection/NowoSearchKitExtension.php` | US-01, US-06, US-08 | FR-CFG-002 |
| `src/NowoSearchKitBundle.php` | US-01 | FR-BUNDLE-001 |
| `src/Resources/config/routes.php` | US-01 | FR-ROUTE-001 |
| `src/Resources/config/services.php` | US-01, US-02 | FR-DI-001 |
| `src/Resources/synonyms/concepts.php` | US-240-04 | FR-240-SYN-001 |

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
| `src/Contract/SearchContextScopeResolverInterface.php` | US-04 | FR-SPI-003 |
| `src/Contract/SearchEngineInterface.php` | US-02 | FR-SPI-004 |
| `src/Contract/IdentifierAwareSearchProviderInterface.php` | US-02 | FR-SPI-005 |
| `src/Contract/MinLengthExemptSearchProviderInterface.php` | US-02 | FR-SPI-006 |
| `src/Contract/MenuTreeSourceInterface.php` | US-06 | FR-PAGES-002 |
| `src/Model/SearchContext.php` | US-04 | FR-MODEL-002 |
| `src/Contract/SearchAccessCheckerInterface.php` | US-240-01 … 07 | FR-240-ACCESS-001 |
| `src/Contract/SearchPageSourceInterface.php` | US-240-01 | FR-240-PAGES-002 |
| `src/Contract/SearchKeywordsAwareInterface.php` | US-240-01 | FR-240-PAGES-003 |
| `src/Contract/SearchActionProviderInterface.php` | US-240-02 | FR-240-ACTIONS-001 |
| `src/Contract/FilterableSearchProviderInterface.php` | US-240-03 | FR-240-FILTER-001 |
| `src/Contract/SearchFilterValuesProviderInterface.php` | US-240-03 | FR-240-FILTER-002 |
| `src/Contract/SearchSynonymProviderInterface.php` | US-240-04 | FR-240-SYN-001 |
| `src/Contract/PaginatedSearchProviderInterface.php` | US-240-06 | FR-240-RESULTS-001 |
| `src/Contract/SearchReferenceResolverInterface.php` | US-240-07 | FR-240-RECENT-001 |
| `src/Model/SearchAccessRequest.php` | US-240-01 … 07 | FR-240-ACCESS-001 |
| `src/Model/SearchPage.php` | US-240-01 | FR-240-PAGES-001 |
| `src/Model/SearchAction.php` | US-240-02 | FR-240-ACTIONS-001 |
| `src/Model/SearchFilter.php` | US-240-03 | FR-240-FILTER-001 |
| `src/Model/SearchFilterValue.php` | US-240-03 | FR-240-FILTER-002 |
| `src/Model/SearchTerm.php` | US-240-04 | FR-240-SYN-001 |
| `src/Model/SearchReference.php` | US-240-07 | FR-240-RECENT-001 |

## Engine & providers

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Search/SearchEngine.php` | US-02, US-08 | FR-ENGINE-001 |
| `src/Provider/MenuPagesProvider.php` | US-06 | FR-PAGES-001 |
| `src/Scope/NullScopeResolver.php` | US-04 | FR-SPI-002 |
| `src/Scope/SearchContextFactory.php` | US-04 | FR-SPI-003 |
| `src/Search/MinLengthExemptPattern.php` | US-02, US-08 | FR-ENGINE-002 |
| `src/Search/SearchRequest.php` | US-240-03, US-240-06 | FR-240-LEAK-001 |
| `src/Search/SearchResult.php` | US-240-03, US-240-06 | FR-240-LEAK-001 |
| `src/Search/Access/SearchAccess.php` | US-240-01 … 07 | FR-240-ACCESS-001 |
| `src/Search/Page/PageRegistryProvider.php` | US-240-01 | FR-240-PAGES-001, FR-240-PAGES-002 |
| `src/Search/Action/ActionCollector.php` | US-240-02 | FR-240-ACTIONS-001 |
| `src/Search/Filter/SearchFilterParser.php` | US-240-03 | FR-240-FILTER-001 |
| `src/Search/Text/TextNormalizer.php` | US-240-04 | FR-240-MATCH-001 |
| `src/Search/Text/TextMatcher.php` | US-240-04 | FR-240-MATCH-001 |
| `src/Search/Text/FoldedText.php` | US-240-04 | FR-240-MATCH-001, FR-240-COST-001 |
| `src/Search/Synonym/SynonymExpander.php` | US-240-04 | FR-240-SYN-001 |
| `src/Search/Synonym/ConceptGroupSynonymProvider.php` | US-240-04 | FR-240-SYN-001 |
| `src/Search/Ranking/SearchRanker.php` | US-240-05 | FR-240-RANK-001 |
| `src/Search/Recent/RecentResolver.php` | US-240-07 | FR-240-RECENT-001 |

## HTTP

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Controller/SearchController.php` | US-02, US-04, US-08, US-240-02, US-240-03 | FR-API-001 |
| `src/Controller/ResultsController.php` | US-240-06 | FR-240-RESULTS-001 |
| `src/Controller/RecentController.php` | US-240-07 | FR-240-RECENT-001 |
| `src/Controller/FilterValuesController.php` | US-240-03 | FR-240-FILTER-002 |
| `src/Http/EndpointGuard.php` | US-240-03, US-240-06, US-240-07 | FR-240-RECENT-001, FR-240-FILTER-002 |

## Twig, markup & theme

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Twig/SearchKitExtension.php` | US-01, US-04, US-05 | FR-TWIG-001 |
| `src/Twig/SearchKitThemeExtension.php` | US-01 | FR-TWIG-004 |
| `src/Form/SearchFieldType.php` | US-01 | FR-TWIG-003 |
| `src/Resources/views/search.html.twig` | US-01, US-03, US-04, US-07 | FR-TWIG-002 |
| `src/Resources/views/results.html.twig` | US-240-06 | FR-240-RESULTS-001 |
| `src/Resources/views/results_layout.html.twig` | US-240-06 | FR-240-RESULTS-001 |
| `src/Resources/views/_results.html.twig` | US-240-06 | FR-240-RESULTS-001 |
| `src/Resources/views/_results_rows.html.twig` | US-240-06 | FR-240-RESULTS-001 |

## Stylesheets

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Resources/public/css/nowo-search.css` | US-03, US-07 | FR-THEME-001 |
| `src/Resources/public/css/nowo-search-popover.css` | US-03, US-07 | FR-THEME-001 |
| `src/Resources/public/css/nowo-search-results.css` | US-240-06 | FR-240-RESULTS-001 |

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
| `src/Resources/assets/src/min-length.ts` | US-02, US-08 | FR-TS-007 |
| `src/Resources/assets/src/motion.ts` | US-03, US-07 | FR-TS-008 |
| `src/Resources/assets/src/filters.ts` | US-240-03 | FR-240-TS-001 |

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
