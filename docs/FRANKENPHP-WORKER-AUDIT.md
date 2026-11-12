# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/search-kit-bundle` (`symfony-bundle`) |
| Audited revision | `1.7.0` (`feat/arch-001-b1`, 2026-11-11; first audit `1.0`, 2026-10-16) |
| Reviewed version | `v1.7.0` (re-checked 2026-11-12 against the kept-alive test below) |
| Audit date | 2026-11-12 (earlier: 2026-11-11, first: 2026-10-16) |
| Method | Manual review of every service under `src/` (17 production units at 1.0, see `specs/001-baseline/code-inventory.md`; the 1.7.0 ports and VOs are interfaces and readonly VOs) + Igor deep audit (`make igor`: 29 service files at 1.7.0, 0 KO) + PHPStan FrankenPHP rulesets |
| **Verdict** | ✅ **Viable under scenario B** — every service is stateless; nothing is memoized |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Kept-alive test (REQ-RUNTIME-002)](#kept-alive-test-req-runtime-002)
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
| Request / user / locale captured in services | ✅ | The controller and the Twig extension read the user and the main request per call; no service stores them. Since 1.7.0 the `SearchContext` VO given to the scope resolver is built per call by the static, stateless `SearchContextFactory` and never kept. |
| Kept-alive test (1.7.0) | ✅ | `tests/Integration/Ddd/DddHostSearchKernelTest.php`: one kernel, `services_resetter` removed (the `kernel.reset` / `services_resetter` is never called), 1,200 iterations (300 before 2026-11-12) alternating alice / bob (endpoint search, scoped page, `SearchEngineInterface::searchFor()`). Each answer holds only that user's projects and scope; memory growth after a 20-iteration warm-up stays under 4 MB. `tests/Integration/ScopeResolverEquivalenceTest.php` also alternates 4 users over 8 pages twice per kernel with no reset. |
| Superglobals, `putenv`, `ini_set`, `setlocale`, `exit` | ✅ | None in `src/` |
| Blocking I/O and timeouts | ✅ | No outbound HTTP or subprocess; search time is the host providers' queries (see [CONFIGURATION — Timeouts](CONFIGURATION.md#timeouts)). |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist` |
| Igor worker-state audit | ✅ | `make igor` in `release-check` (REQ-CS-008) |

## Kept-alive test (REQ-RUNTIME-002)

Test: `Nowo\SearchKitBundle\Tests\Integration\Ddd\DddHostSearchKernelTest::testTheHostPortsServeEachUserOnlyTheirOwnProjectsInOneKeptAliveKernel`. It boots **one** kernel, asserts that `services_resetter` does not exist, and runs 1,200 iterations alternating two users (alice / bob): the HTTP search endpoint, the scoped page and `SearchEngineInterface::searchFor()` each answer with only that user's projects and scope. Memory growth after a 20-iteration warm-up must stay below 4 MiB. A guest is still refused (401) afterwards.

Result: nothing is carried between requests, so the bundle is **safe with kernel reset false**.

Rule 7: the bundle has no persistence layer and never clears, flushes or resets the host's default EntityManager; the host providers own their data access.

## Rules for future code

- Never memoize per-request data (scope, user, results) in a service property; put it on the `Request` or in a
  `ResetInterface` service.
- Host providers must follow the same rule (they are shared services too).
- Any outbound client added later needs explicit timeouts (REQ-RUNTIME-001).

Re-run this audit (and update the table) whenever services are added under `src/`.
