# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/search-kit-bundle` (`symfony-bundle`) |
| Audited revision | `1.0` (`main`, 2026-10-16) |
| Audit date | 2026-10-16 |
| Method | Manual review of every service under `src/` (17 production units, see `specs/001-baseline/code-inventory.md`) + Igor deep audit (`make igor`: 21 service files, 0 KO) + PHPStan FrankenPHP rulesets |
| **Verdict** | ✅ **Viable under scenario B** — every service is stateless; nothing is memoized |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Rules for future code](#rules-for-future-code)

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests.

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` are reset between requests.
- **B — no reset at all:** any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | `SearchEngine`, `SearchController`, `SearchIcons`, `MenuPagesProvider` are `final readonly`; `SearchKitExtension` and `NullScopeResolver` hold only injected collaborators and configuration. |
| Caches | ✅ | None in the bundle. The rate limiter state lives in the framework limiter storage (shared cache pool). |
| Static properties / `static` locals | ✅ | None |
| Request / user / locale captured in services | ✅ | The controller and the Twig extension read the user and the main request per call; no service stores them. |
| Superglobals, `putenv`, `ini_set`, `setlocale`, `exit` | ✅ | None in `src/` |
| Blocking I/O and timeouts | ✅ | No outbound HTTP or subprocess; search time is the host providers' queries (see [CONFIGURATION — Timeouts](CONFIGURATION.md#timeouts)). |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist` |
| Igor worker-state audit | ✅ | `make igor` in `release-check` (REQ-CS-008) |

## Rules for future code

- Never memoize per-request data (scope, user, results) in a service property; put it on the `Request` or in a
  `ResetInterface` service.
- Host providers must follow the same rule (they are shared services too).
- Any outbound client added later needs explicit timeouts (REQ-RUNTIME-001).

Re-run this audit (and update the table) whenever services are added under `src/`.
