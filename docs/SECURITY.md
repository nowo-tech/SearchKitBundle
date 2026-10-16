# Security

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model and mitigations](#threat-model-and-mitigations)
- [Secrets and cryptography](#secrets-and-cryptography)
- [Logging](#logging)
- [Dependencies](#dependencies)
- [Permissions and exposure](#permissions-and-exposure)
- [Host responsibilities (residual risks)](#host-responsibilities-residual-risks)
- [AI security audit](#ai-security-audit)
- [Reporting a vulnerability](#reporting-a-vulnerability)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)

## Scope

This document is the security analysis of **SearchKitBundle** (`nowo-tech/search-kit-bundle`): what it accepts, what can go
wrong and how the bundle prevents it, plus what the host application must do. The short reporting policy lives in
[`.github/SECURITY.md`](../.github/SECURITY.md).

**Covered:** the bundle's PHP code (controller, engine, models, built-in Pages provider, Twig extension, form type, DI
defaults), its Twig view, the TypeScript module, the CSS and the Flex recipe. **Not covered:** the host's users,
authentication and firewall, and the host's providers and scope resolver (they decide what each user may see).

## Attack surface

| Input | Where | Who |
| --- | --- | --- |
| `GET /search?q=&scope=` | `SearchController` | Signed-in users granted `role` (401 / 403 otherwise) |
| Query text (≤ `max_length`), scope id (`[A-Za-z0-9._:-]{1,128}`) | `SearchEngine` → host providers | Same |
| Host quick actions passed to the template | `SearchKitExtension::config()` | Host templates |
| Configuration (`nowo_search_kit`), Flex recipe | DI extension | Deployer |
| Browser storage (`recent.storage_key`) | TypeScript module | Same browser, same origin |

## Threat model and mitigations

| Threat | Mitigation |
| --- | --- |
| **Unauthenticated access** | The controller requires a signed-in user granted `role` (`AccessDeniedException`: 401 / login for guests, 403 otherwise); the template renders nothing for them. |
| **Authorization / existence oracle** | The kit returns only what providers return; the SPI contract (documented on the interface and in USAGE.md) requires visible rows only, counts of visible matches only, and **no rows (never 403)** for an unknown or forbidden scope. The scope chip is resolved by the host after its access check. |
| **XSS** | Titles, subtitles, badges, labels and the query are inserted with `textContent` / text nodes; matches are wrapped in `<mark>` elements created through the DOM. Only row icons (SVG rendered server side from the application's own UX icon set) use `innerHTML` — an ESLint rule forbids any other use. Badge tones and group keys are reduced to `[A-Za-z0-9_-]` before they reach a class or id. Twig auto-escapes everything else (`json_encode|e('html_attr')` for the config). |
| **Open redirect / `javascript:` URLs** | `SearchItem` refuses URLs other than same-site paths (not `//host`, not `/\host`) and http(s); "See all" and host quick actions are filtered the same way; the browser re-checks every URL before rendering and before navigating; recents keep same-site paths only. |
| **Injection** | The kit builds no SQL; providers must use parameters. No shell, no `eval`, no `unserialize`. |
| **Information leaks** | Responses are `Cache-Control: no-store, private` and `X-Robots-Tag: noindex`; errors never include exception text; a failing provider is logged with its class and the exception class only — never the query (personal data). |
| **Abuse / DoS** | Per-user rate limiter (sliding window 30 / 10 s by default, 429), `min_length` / `max_length`, `per_group` rows, browser debounce and request abort. |
| **CSRF** | Read-only GET with no side effect; the search field form is never submitted (no token by design, REQ-SEC-005 covers mutations). |
| **Shared devices** | Recents hold titles and paths of pages the user opened from the box, in `localStorage` of that origin only; `recent.enabled: false` turns them off; "Clear" empties them. |
| **Worker-mode state leaks** | No per-request data in shared services (see [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)); Igor audit in `release-check`. |

## Secrets and cryptography

The bundle ships no secret, stores none and signs nothing. The rate-limiter key is a SHA-256 of the user identifier
(no personal data in the cache).

## Logging

Only failing providers are logged (error level, channel `nowo_search_kit`): provider class and exception class. No query
text, no user data.

## Dependencies

`composer audit` runs in CI (`Composer audit (Critical/High)` step) and before each release; Dependabot keeps
dependencies current. Security fixes are released as patch versions of the supported line (see
[`.github/SECURITY.md`](../.github/SECURITY.md)).

## Permissions and exposure

| Exposure | Restriction |
| --- | --- |
| `GET /search` | Behind the host's session firewall; the bundle re-checks the user and `role` on every request |
| Pages group | Only menu entries the menu service returns for the current user (DashboardMenuBundle filters by its permission checkers) |
| Provider groups | Exactly what each host provider returns |

## Host responsibilities (residual risks)

- **Providers:** check permissions exactly like the target pages; never count hidden records; treat `scope` as untrusted
  (unknown or forbidden → null); bound every query (`LIMIT $query->limit`).
- **Scope resolver:** return a scope only after the access check (the chip shows its label).
- **Rate limiter storage:** shared by every worker / replica (e.g. Redis), or the limit only holds per process.
- **Icons:** row icons are SVG from your own UX icon set (local files or Iconify) — do not point UX Icons at an
  untrusted icon source.
- **Browser:** a Content-Security-Policy is recommended (the bundle needs no inline script; assets load from the
  `nowo_search_kit` package).

## AI security audit

| Field | Value |
| --- | --- |
| Date | 2026-10-16 |
| Method | AI static review (Claude Code agent) of the 1.0.0 code against BUNDLES_SECURITY_ANALYSIS.md §1 / REQ-SEC-004 |
| Result | Overall **Low**, **Pass (good)** — no Critical / High / Medium |
| Findings | SEARCH-SEC-01 (Low) protocol-relative recents accepted by the original application code → **fixed** (same-site paths only, `//` and `/\` refused, PHP and TS); SEARCH-SEC-02 (Low) `javascript:` / `data:` row URLs could reach `href` → **fixed** (`SearchItem::isSafeUrl()`, browser re-check); SEARCH-SEC-03 (Info) query text in logs → **avoided** (class names only); SEARCH-SEC-04 (Info) provider permission model → **documented** host responsibility |
| Recorded in | `BUNDLES_SECURITY_ANALYSIS.md` (monorepo security report) |

## Reporting a vulnerability

Report security issues **privately**:

1. Do **not** open a public GitHub issue for security-sensitive bugs.
2. Use [GitHub Security Advisories](https://github.com/nowo-tech/SearchKitBundle/security/advisories) or email **hectorfranco@nowo.tech**.
3. Include steps to reproduce, affected versions, and impact.
4. We will acknowledge and coordinate disclosure after a fix is available.

See also [`.github/SECURITY.md`](../.github/SECURITY.md) for supported versions and reporting policy.

## Release security checklist (12.4.1)

Before each release, the maintainer confirms each item (note in the release PR or tag message is sufficient).

| Item | Check |
| --- | --- |
| `docs/SECURITY.md` and `.github/SECURITY.md` up to date | ☐ |
| `.env` / secrets in `.gitignore`; no credentials in the repository | ☐ |
| Flex recipe / published config contains no secrets | ☐ |
| Input validated and output escaped (`textContent`, `<mark>` via DOM, safe URLs) | ☐ |
| Authorization on the endpoint (signed-in user + `role`; providers return visible rows only) | ☐ |
| No session-authenticated mutation (read-only GET; CSRF not applicable) | ☐ |
| `composer audit` run; known issues reviewed | ☐ |
| Logs contain no query text, personal data or secrets | ☐ |
| Limits / DoS: per-user rate limit, min / max length, rows per group | ☐ |
| AI security audit (REQ-SEC-004) passed — latest grade in `BUNDLES_SECURITY_ANALYSIS.md` | ☐ |
