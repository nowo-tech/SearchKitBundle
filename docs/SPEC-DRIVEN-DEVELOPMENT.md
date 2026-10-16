# Spec-driven development

In this repository, **spec-driven development** has three layers that stay in sync:

1. **GitHub Spec Kit baseline** — [`specs/001-baseline/`](../specs/001-baseline/) ([`spec.md`](../specs/001-baseline/spec.md), [`code-inventory.md`](../specs/001-baseline/code-inventory.md)), initialized with [GitHub Spec Kit](https://github.com/github/spec-kit) (`.specify/`, **Cursor Agent** skills in `.cursor/skills/speckit-*`). The inventory maps **100%** of production code in `src/`. **How to install, initialize, and use Spec Kit:** [`SPEC-KIT.md`](SPEC-KIT.md).
2. **Product behavior** — what **SearchKitBundle** guarantees to applications that integrate it (see [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`INSTALLATION.md`](INSTALLATION.md)). **PHPUnit** and **PHPStan** (and **Vitest** when applicable) enforce contracts in CI where applicable.
3. **Traceability anchors** — stable **`REQ-*`** identifiers in Makefiles and demos (when present) so changes to scripts, ports, and demo workflows stay discoverable from issues and PRs.

There is no separate executable spec language (for example Gherkin); Spec Kit specs, tests, and static analysis are the mechanical proof alongside this document.

---

## Table of contents

- [User stories](#user-stories)
- [Bundle functional scope](#bundle-functional-scope)
- [Validating the functional spec](#validating-the-functional-spec)
- [Requirement identifiers (`REQ-*`)](#requirement-identifiers-req-)
- [Suggested workflow for contributors](#suggested-workflow-for-contributors)
- [Relationship to Engram / external checklists](#relationship-to-engram--external-checklists)
- [GitHub Spec Kit (summary)](#github-spec-kit-summary)
- [See also](#see-also)

## User stories

The IDs match the user scenarios of the baseline spec ([`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md#user-scenarios)), where each has Given/When/Then acceptance criteria.

| ID | Story | Integrator docs |
| --- | --- | --- |
| US-01 | **As an** integrator, **I want** to install the bundle, import the routes and render one partial **so that** signed-in users get a header search box. | [INSTALLATION](INSTALLATION.md#requirements) ([routes](INSTALLATION.md#routes), [render](INSTALLATION.md#render-the-search-box), [assets](INSTALLATION.md#publish-assets)) · [CONFIGURATION](CONFIGURATION.md#full-reference) |
| US-02 | **As an** integrator, **I want** to add one provider per group **so that** users find my records, ordered by priority, without the kit deciding permissions. | [USAGE — Providers](USAGE.md#providers) ([security contract](USAGE.md#security-contract)) |
| US-03 | **As a** user, **I want** to open the search with the magnifier, Ctrl/⌘ K or "/", move with the arrows and open with Enter (Ctrl/⌘ Enter in a new tab) **so that** I reach any page from the keyboard. | [USAGE — Browser module](USAGE.md#browser-module) · [CONFIGURATION — Options](CONFIGURATION.md#options) |
| US-04 | **As a** user inside a project, **I want** the search scoped to it (removable chip) **so that** I find its records first. | [USAGE — Scope resolver](USAGE.md#scope-resolver) |
| US-05 | **As a** user, **I want** my recent picks and quick actions while the field is empty **so that** I go back to frequent places. | [CONFIGURATION — Quick actions](CONFIGURATION.md#quick-actions) |
| US-06 | **As an** integrator using DashboardMenuBundle, **I want** a Pages group from my menus **so that** navigation entries are searchable with no code. | [CONFIGURATION — Pages group](CONFIGURATION.md#pages-group) |
| US-07 | **As an** integrator, **I want** overridable Twig blocks, `--nowo-search-*` tokens, UX Icons and events **so that** it matches my design system (morphing icon, Turbo). | [USAGE — The header template](USAGE.md#the-header-template) · [USAGE — Theme tokens](USAGE.md#theme-tokens) · [USAGE — Events](USAGE.md#events) |
| US-08 | **As an** operator, **I want** a per-user rate limit, input limits and no caching **so that** the endpoint cannot be abused or leak through caches. | [CONFIGURATION — Rate limit](CONFIGURATION.md#rate-limit) · [SECURITY](SECURITY.md) |

**Out of scope:** indexing or querying application data (providers do it), a full results page, and behavior outside declared PHP/Symfony compatibility ranges.

---

## Bundle functional scope

**Goal:** global header search (command palette) for Symfony: a magnifier that stretches into a field, grouped permission-aware results from the application's providers, keyboard navigation, recents, UX Icons and theme tokens.

**Current state:** `1.0.x` — implemented and covered by the baseline spec [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) (user scenarios US-01…US-08, `FR-*` by domain) and its 100% code inventory. See [`ARCHITECTURE.md`](ARCHITECTURE.md) for the design.

**In scope**

- Provider SPI (`SearchProviderInterface`, priority order) and scope SPI (`SearchScopeResolverInterface`).
- Rate-limited, no-store JSON endpoint for signed-in users.
- Built-in Pages group from DashboardMenuBundle menus.
- Header Twig partial (overridable blocks, Symfony Form field), TypeScript module (IIFE + Stimulus wrapper), CSS tokens, UX Icons with built-in fallback; 7 locales.
- Documented integration (root `README.md` and `docs/`), configuration in [`CONFIGURATION.md`](CONFIGURATION.md), runtime behavior in [`USAGE.md`](USAGE.md).
- Consumer-facing changes in [`CHANGELOG.md`](CHANGELOG.md) and [`UPGRADING.md`](UPGRADING.md).

**Explicit non-goals**

- Deciding permissions or querying the host's data (providers do; FULLTEXT helpers live in the host or AuditKitBundle).
- A full results page (each group may link to its own list with "See all").
- **`demo/`** UI: illustrative unless explicitly documented as stable API.

---

## Validating the functional spec

- Run **`make qa`** or **`make release-check`** as documented in [`CONTRIBUTING.md`](CONTRIBUTING.md).
- Run **PHPUnit** and **Vitest** locally and in CI for code changes.
- New or changed behavior should add or adjust tests under `tests/` and `src/Resources/assets/tests/`.

---

## Requirement identifiers (`REQ-*`)

| ID | Where | What it marks |
| --- | --- | --- |
| `REQ-DEMO-005` | `demo/symfony8/Makefile` | Canonical `make up`: `Waiting…`, `Installing…`, `Demo started at:` from `PORT`. |
| `REQ-DEMO-007` | `demo/Makefile`, demo Makefiles | `update-bundle` before demo tests in `release-check`. |
| `REQ-MAKE-001` | Root `Makefile` | Docker-driven development workflow for the bundle. |
| `REQ-MAKE-008` | Root `Makefile` | `update-deps` via shared `.scripts/`. |
| `REQ-MAKE-002` | Root `Makefile` | `release-check` runs its steps sequentially in spec order. |
| `REQ-CS-008` | Root `Makefile` | `make igor` worker-state audit (FrankenPHP). |
| `REQ-TWIG-005` | Root `Makefile` | `check-no-raw-html-form`: no raw `<form>` / `<input>` / `<textarea>` / `<select>` in Twig. |
| `REQ-GIT-001` | Root `Makefile` | `setup-hooks`, `check-no-cursor-coauthor`, `strip-cursor-coauthor-from-history`. |
| `REQ-TEST-011` | Root / demo `Makefile` | `demo-smoke`: the demo boots and answers HTTP 200. |
| `REQ-DEMO-013` | `demo/symfony8/Makefile` | `test-e2e` (Playwright) and `demo-screenshots` (WebP under `docs/images/demo/`). |

When you change scripted behavior, update the existing `REQ-*` comment or add a new ID and document it here.

---

## Suggested workflow for contributors

1. Clarify behavior in an issue or draft PR (product + Makefiles/demos).
2. Implement with tests and static analysis.
3. Anchor scripts and demos when dev UX changes.
4. Ship integrator docs when configuration or public behavior changes.
5. **Keep Spec Kit artifacts in sync** when production code under `src/` changes:
   - Update [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and [`code-inventory.md`](../specs/001-baseline/code-inventory.md).
   - Follow the maintainer checklist in [`SPEC-KIT.md`](SPEC-KIT.md).
   - For **new features**, use Cursor Agent skills (`/speckit-specify`, `/speckit-plan`, `/speckit-tasks`) as documented in SPEC-KIT.

---

## Relationship to Engram / external checklists

[`ENGRAM.md`](ENGRAM.md) covers Nowo-wide documentation checklist items. This document ties together **what the package does**, **how we verify it**, and **local `REQ-*` habits**. Both coexist: Engram for org-level compliance, this file for product + traceability.

---

## GitHub Spec Kit (summary)

This repository uses [GitHub Spec Kit](https://github.com/github/spec-kit) with **Cursor Agent** (`cursor-agent` integration).

| Artifact | Path |
| --- | --- |
| **Operator manual** (install, init, usage) | [`SPEC-KIT.md`](SPEC-KIT.md) |
| Baseline spec | [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) |
| Code inventory (100%) | [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md) |
| Constitution | [`.specify/memory/constitution.md`](../.specify/memory/constitution.md) |
| Cursor Agent skills | [`.cursor/skills/`](../.cursor/skills/) (`speckit-*`) |

**Quick start (maintainers):**

```bash
# Install Specify CLI (once per machine) — see SPEC-KIT.md
specify init --here --force --integration cursor-agent --script sh
specify integration list   # Cursor → installed (default)
```

In Cursor Agent, start a new feature with `/speckit-specify <description>`. For day-to-day tooling details, skills reference, folder layout, and troubleshooting, read **[`SPEC-KIT.md`](SPEC-KIT.md)**.

---

## See also

- [`SPEC-KIT.md`](SPEC-KIT.md) — GitHub Spec Kit manual (install, structure, usage)
- [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md)
- [`USAGE.md`](USAGE.md)
- [`CONFIGURATION.md`](CONFIGURATION.md)
- [`INSTALLATION.md`](INSTALLATION.md)
- [`CONTRIBUTING.md`](CONTRIBUTING.md)
- [`RELEASE.md`](RELEASE.md)
- [`DEMO-FRANKENPHP.md`](DEMO-FRANKENPHP.md)
