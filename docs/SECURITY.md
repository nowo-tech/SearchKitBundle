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
| `GET /search?q=&scope=` | `SearchController` | Signed-in users granted `role` (JSON 401 / 403 otherwise, independent of the firewall entry point) |
| Query text (≤ `max_length`), scope id (`[A-Za-z0-9._:-]{1,128}`) | `SearchEngine` → host providers | Same |
| Host quick actions passed to the template | `SearchKitExtension::config()` | Host templates |
| Configuration (`nowo_search_kit`), Flex recipe | DI extension | Deployer |
| Browser storage (`recent.storage_key`) | TypeScript module | Same browser, same origin |

## Threat model and mitigations

| Threat | Mitigation |
| --- | --- |
| **Unauthenticated access** | The controller requires a signed-in user granted `role` and answers JSON `401 {error: unauthenticated}` / `403 {error: forbidden}` itself; the template renders nothing for them. Import the route behind your session firewall (otherwise there is no user and every call is 401). |
| **Authorization / existence oracle** | The kit returns only what providers return; the SPI contract (documented on the interface and in USAGE.md) requires visible rows only, counts of visible matches only, and **no rows (never 403)** for an unknown or forbidden scope. The scope chip is resolved by the host after its access check. |
| **XSS** | Titles, subtitles, badges, labels and the query are inserted with `textContent` / text nodes; matches are wrapped in `<mark>` elements created through the DOM. Row icons (SVG rendered server side from the application's own UX icon set) are parsed into an inert document by `sanitizeSvg()` and appended as nodes after keeping only drawing elements (no script, `foreignObject`, animation, link or external reference) and dropping event handlers, `href` and `javascript:` / `data:` values; markup that is not a single `<svg>` falls back to the kind icon. An ESLint rule forbids every `innerHTML` / `outerHTML` assignment and `insertAdjacentHTML`. Badge tones, kinds and group keys are reduced to `[A-Za-z0-9_-]` before they reach a class, an id or a selector. Twig auto-escapes everything else (`json_encode|e('html_attr')` for the config) except the icon SVG, output as trusted `Markup` from the configuration / UX Icons. |
| **Open redirect / `javascript:` URLs** | `SearchItem` refuses URLs other than same-site paths (not `//host`, not `/\host`) and http(s), and any URL with a control character or a space (browsers strip TAB / CR / LF, so `/\t/host` would become `//host`); "See all", host quick actions (template) and configured `quick_actions[].url` (container compilation) are checked the same way; the browser re-checks every URL before rendering and before navigating; recents keep same-site paths only. |
| **Injection** | The kit builds no SQL; providers must use parameters. No shell, no `eval`, no `unserialize`. |
| **Information leaks** | Responses are `Cache-Control: no-store, private` and `X-Robots-Tag: noindex`; error responses carry stable codes, never exception text; a failing provider is logged with its class and the exception class only — never the query (personal data); a row icon that cannot be rendered (unknown name, icon source failure) falls back to the kind icon instead of failing the search. |
| **Abuse / DoS** | Per-user rate limiter (sliding window 30 / 10 s by default, 429), `min_length` / `max_length`, `per_group` rows, browser debounce and request abort. |
| **CSRF** | Read-only GET with no side effect; the search field form is never submitted (no token by design, REQ-SEC-005 covers mutations). |
| **Shared devices** | Recents hold titles and paths of pages the user opened from the box, in `localStorage` of that origin only, under a **per-user key** (`storage_key` + an HMAC of the user identifier keyed with `kernel.secret` — the identifier never reaches the browser), so the next account on the same browser does not see them; they survive logout for the same account; `recent.enabled: false` turns them off; "Clear" empties them. |
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
  untrusted icon source, and let providers name icons from a fixed set (never from user data: with on-demand Iconify,
  an unknown name triggers an outbound fetch).
- **Firewall:** import the search route behind your session firewall; escape `LIKE` wildcards in provider queries.
- **Browser:** a Content-Security-Policy is recommended (the bundle needs no inline script; assets load from the
  `nowo_search_kit` package).

## AI security audit

| Field | Value |
| --- | --- |
| Date | 2026-10-16 |
| Method | Independent AI static review (Claude Code agent) of the 1.0.0 code against BUNDLES_SECURITY_ANALYSIS.md §1 / REQ-SEC-004 |
| First result | Overall **Low**, **Pass (good)** conditional on SEARCH-SEC-01 — no Critical / High / Medium |
| Re-audit | All Low findings **fixed** before tagging 1.0.0 (same day) → Overall **Low**, **Pass (good)** |
| Findings | SEARCH-SEC-01 (Low) TAB / CR / LF same-site path bypass (`/\t/host`) → **fixed** (control characters and spaces refused in PHP and TS, tests); SEARCH-SEC-02 (Low) icon rendering errors failed the whole search → **fixed** (any renderer error falls back); SEARCH-SEC-03 (Low) recents shared between accounts on one browser → **fixed** (per-user HMAC key suffix); SEARCH-SEC-04 (Info) configured quick-action URLs not checked server side → **fixed** (configuration validation); SEARCH-SEC-05 (Info) `innerHTML` for icon SVG → **fixed in 1.0.1** (`sanitizeSvg()`, no `innerHTML` left; REQ-UX-001); SEARCH-SEC-06 (Info) `$&` replacement patterns in messages → **fixed**; SEARCH-SEC-07 (Info) stale ESLint wording → **fixed**; SEARCH-SEC-08 (Info) guest status depended on the firewall → **fixed** (JSON 401 / 403); SEARCH-SEC-09 (Info, demo) demo-only settings → **documented** in `demo/README.md` |
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
