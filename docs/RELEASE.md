# Release

This checklist helps maintainers prepare and publish a release safely.

## Table of contents

- [Pre-release](#pre-release)
- [Security checklist (12.4.1)](#security-checklist-1241)
- [GitHub About (REQ-DOCS-018)](#github-about-req-docs-018)
- [Tag and publish](#tag-and-publish)
- [Post-release checks](#post-release-checks)
- [Coverage goals](#coverage-goals)
- [Release history](#release-history)

## Pre-release

Run the full release pipeline:

```bash
make release-check
```

Expected steps:

- `check-no-cursor-coauthor` (REQ-GIT-001)
- Asset build (`pnpm run build`)
- Composer validation and lock sync
- Code style checks
- Static analysis (Rector dry run + PHPStan)
- PHP and TypeScript test suites with coverage
- Demo verification (`demo/Makefile` `release-check`)

After creating the release commit and tag, run `make check-no-cursor-coauthor` again **before** `git push` (REQ-GIT-001).

## Security checklist (12.4.1)

Before tagging, confirm each item in [SECURITY.md — Release security checklist](SECURITY.md#release-security-checklist-1241). Note confirmation in the release PR or tag message.

## GitHub About (REQ-DOCS-018)

GitHub repository **About** is not stored in git. Copy the values from [GITHUB.md](GITHUB.md). After creating or transferring `nowo-tech/SearchKitBundle`, set:

| Field | Value |
| --- | --- |
| **Description** | Global header search (command palette) for Symfony: a magnifier that stretches into a field, grouped permission-aware results from your own providers, Ctrl/⌘K, keyboard navigation, recents, UX Icons and theme tokens. |
| **Website** | https://packagist.org/packages/nowo-tech/search-kit-bundle |
| **Topics** | `php`, `symfony`, `symfony-bundle`, `search`, `global-search`, `command-palette`, `typeahead`, `frankenphp`, `typescript` |

## Tag and publish

1. Move `[Unreleased]` entries in `docs/CHANGELOG.md` to a new `## [X.Y.Z] - YYYY-MM-DD` section.
2. Update `docs/UPGRADING.md` if consumers must change code or configuration.
3. Create an **annotated** tag: `git tag -a vX.Y.Z -m "Release vX.Y.Z"`.
4. Push the tag: `git push origin vX.Y.Z`.
5. Confirm GitHub workflows `release.yml` and `sync-releases.yml` completed successfully (when Actions are unavailable, create the release manually: `gh release create vX.Y.Z --notes-file <CHANGELOG section>`).

## Post-release checks

- Verify Packagist metadata is updated.
- Confirm the GitHub release contains the tag message and changelog section.
- Validate installation in a clean Symfony app:

```bash
composer require nowo-tech/search-kit-bundle
```

- Smoke-test the demo home page and the header search (Ctrl/⌘ K).

## Coverage goals

- **PHP**: **≥99%** line coverage (target **100%**; `make test-coverage`)
- **TypeScript**: **≥90%** of the minimum of statements / branches / functions / lines (`make test-ts`; Vitest thresholds 100 / 95 branches)

Update README **Tests and coverage** percentages after each release when coverage changes materially.

## Release history

| Version | Date | Notes |
| --- | --- | --- |
| 1.0.1 | 2026-10-16 | Compliance patch: icon SVG through `sanitizeSvg()` (no `innerHTML`, REQ-UX-001), override and Forms docs (REQ-TWIG-001 / REQ-TWIG-005), canonical bug report template (REQ-DOCS-010). |
| 1.0.0 | 2026-10-16 | First release: header search box, provider and scope SPIs, Pages group (DashboardMenuBundle), rate-limited JSON endpoint, TypeScript module (IIFE + Stimulus wrapper), theme tokens, 7 locales, Spec Kit baseline, SEC-004 Pass (good). |
