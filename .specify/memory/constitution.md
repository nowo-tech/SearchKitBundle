# SearchKitBundle Constitution

## Core Principles

### I. Documented integrator contract
Product behavior lives in `specs/001-baseline/spec.md`, `docs/SPEC-DRIVEN-DEVELOPMENT.md`, and integrator docs (`USAGE.md`, `CONFIGURATION.md`, `ARCHITECTURE.md`, `SECURITY.md`). Demos are illustrative unless promoted in the spec.

### II. Spec-first, test-proven
PHPUnit, PHPStan, and Vitest are the mechanical proof. Behavioral changes require tests.

### III. 100% code inventory traceability
Every production unit under `src/` (PHP, Twig, translation YAML, TypeScript sources, the stylesheets) must appear in `specs/001-baseline/code-inventory.md`. The Vite IIFE output is documented as `FR-BUILD-001`, not an extra inventory row. New files require spec updates in the same PR.

### IV. The host owns data and permissions
The bundle never queries application data and never decides who may see what. Every group comes from a host `SearchProviderInterface`, which must return only rows the current user may open (no counts or hints of anything else) and must treat the scope id as untrusted input (unknown or forbidden scope → no rows, never 403). The scope chip comes from the host `SearchScopeResolverInterface`, after its own access check.

### V. Privacy and safety defaults
Query text is never logged. User-facing text reaches the DOM only as text nodes (matches wrapped in `<mark>` elements built through the DOM); only SVG from the application's icon set may be inserted as HTML. Row URLs are same-site paths or http(s) only, checked in PHP and again in the browser; recents keep text and same-site paths only. The endpoint is read-only, rate limited per user and never cached.

### VI. Cursor + Spec Kit
GitHub Spec Kit is initialized with **Cursor Agent** (`cursor-agent`). Skills live in `.cursor/skills/speckit-*`.

### VII. Symfony compatibility
Follow declared PHP/Symfony ranges in `composer.json` and README badges. FrankenPHP worker mode: no per-request state in services. See `docs/FRANKENPHP-WORKER-AUDIT.md`.

## Governance
Amendments update this file, baseline spec when principles affect behavior, and `CHANGELOG.md` when consumer-visible.

Versioning (SemVer): **MAJOR** when a principle is removed or redefined incompatibly; **MINOR** when a principle or section is added or materially expanded; **PATCH** for clarifications and factual corrections that do not change intent.

**Version**: 1.0.0 | **Ratified**: 2026-10-16 | **Last Amended**: 2026-10-16
