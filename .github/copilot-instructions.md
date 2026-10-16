## AI contribution guidelines — Search Kit Bundle

Follow these rules when contributing PHP, TypeScript, Twig, and documentation to this repository.

---

## Project scope

- **Type:** Standalone Symfony bundle (`nowo-tech/search-kit-bundle`), global header search (provider SPI, rate-limited JSON endpoint, TypeScript search box).
- **PHP:** `>=8.3 <8.6` with `declare(strict_types=1);` in every PHP file.
- **Symfony:** **7.4**, **8.0**, and **8.1+** (`^7.4 || ^8.0` on `symfony/*` constraints). Symfony 8 requires PHP 8.4+.
- **Code:** `src/` (`Nowo\SearchKitBundle\`).
- **Frontend:** TypeScript + Vite + **pnpm** in `src/Resources/assets/`; published IIFE `src/Resources/public/js/nowo-search.js`. The Symfony 8 demo compiles the same sources with **Pentatrion Vite** (`vite-plugin-symfony`).
- **Language:** PHPDoc, inline comments, and user-facing docs in **English** only.

---

## PHP standards

- PSR-12 + Symfony coding standards; run `make cs-check` before finishing.
- Use **strict comparisons** (`===`) and constructor injection.
- Prefer `final` classes; keep BC on public config keys, route names, and Twig template paths.
- Wire services in `src/Resources/config/services.php`.
- Preserve the DI extension alias: `nowo_search_kit` (flat tree: one search box per application).
- Controllers use `#[Route]` attributes; route names are prefixed `nowo_search_kit_`.

---

## Bundle-specific conventions

- Twig namespace: `@NowoSearchKitBundle`; translation domain: `NowoSearchKitBundle` (REQ-TWIG-002 / REQ-I18N-003); `nowo_search_kit` is only the config root key.
- Asset package: `nowo_search_kit` (`base_path: /bundles/nowosearchkit`); load `asset('js/nowo-search.js', 'nowo_search_kit')`.
- CSS classes `.nowo-search__*`, CSS custom properties `--nowo-search-*`, DOM hooks `data-part` / `data-nowo-search-*`, Stimulus identifier `nowo-search-kit`.
- Permissions come from the host providers (`SearchProviderInterface`); the scope id is untrusted input.
- Never log query text; never render user text as HTML (text nodes only; only icon-set SVG may use innerHTML).
- No per-request state in services (FrankenPHP worker mode).

---

## Tests and quality

- PHPUnit target: **100% line coverage** on `src/` (gate ≥99%); justify exclusions in `docs/COVERAGE.md`.
- TypeScript tests via Vitest: `make test-ts` (thresholds 100% lines / statements / functions, 95% branches; gate ≥90%).
- Full gate: `make release-check` (style, static analysis, PHP + TS coverage, demos).

---

## Documentation

- Keep `README.md` badges and the **Documentation** section aligned with `docs/`.
- Update `docs/CHANGELOG.md` and `docs/UPGRADING.md` for user-visible changes.
- Flex recipe lives in `.symfony/recipe/nowo-tech/search-kit-bundle/1.0/`; document Flex steps in `docs/INSTALLATION.md`.

---

## Do not

- Commit secrets, `.env` files, or demo `var/` caches.
- Add Spanish PHPDoc or comments in `src/`.
- Publish a separate npm package for the browser assets.
- Touch unrelated files when fixing a focused issue.

## Commits

- Never add AI co-author trailers: no `Co-authored-by: Cursor` / `cursoragent@cursor.com`, no `Co-Authored-By: Claude …` / `noreply@anthropic.com` (REQ-GIT-001).
