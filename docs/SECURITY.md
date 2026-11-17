# Security

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model and mitigations](#threat-model-and-mitigations)
- [Anti-leak model (1.8.0)](#anti-leak-model-180)
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
| Configuration (`nowo_search_kit`, including the `toggle_icon_template` path and `min_length_exempt_patterns`), Flex recipe | DI extension | Deployer |
| Browser storage (`recent.storage_key`) | TypeScript module | Same browser, same origin |
| 1.8.0: typed filters in `q`, `from` path, `GET {prefix}/filters?key=&q=`, `POST {prefix}/recent` (JSON refs + `X-CSRF-Token`), `GET {prefix}/results` | Engine, controllers | Signed-in users granted `role` (same guard and rate limit as the endpoint) |

## Threat model and mitigations

| Threat | Mitigation |
| --- | --- |
| **Unauthenticated access** | The controller requires a signed-in user granted `role` and answers JSON `401 {error: unauthenticated}` / `403 {error: forbidden}` itself; the template renders nothing for them. Import the route behind your session firewall (otherwise there is no user and every call is 401). |
| **Authorization / existence oracle** | The kit returns only what providers return; the SPI contract (documented on the interface and in USAGE.md) requires visible rows only, counts of visible matches only, and **no rows (never 403)** for an unknown or forbidden scope. The scope chip is resolved by the host after its access check. |
| **XSS** | Titles, subtitles, badges, labels and the query are inserted with `textContent` / text nodes; matches are wrapped in `<mark>` elements created through the DOM. Row icons (SVG rendered server side from the application's own UX icon set) are parsed into an inert document by `sanitizeSvg()` and appended as nodes after keeping only drawing elements (no script, `foreignObject`, animation, link or external reference) and dropping event handlers, `href` and `javascript:` / `data:` values; markup that is not a single `<svg>` falls back to the kind icon. An ESLint rule forbids every `innerHTML` / `outerHTML` assignment and `insertAdjacentHTML`. Badge tones, kinds and group keys are reduced to `[A-Za-z0-9_-]` before they reach a class, an id or a selector. Twig auto-escapes everything else (`json_encode|e('html_attr')` for the config) except the icon SVG, output as trusted `Markup` from the configuration / UX Icons. |
| **Template selection** | `toggle_icon_template` comes from the container configuration only, never from the request, a provider or the browser, and the path is not part of the browser configuration. The partial is host code: it renders as a trusted template, with the same context as the block. Guests and users without `role` render nothing, so they never reach it. |
| **Open redirect / `javascript:` URLs** | `SearchItem` refuses URLs other than same-site paths (not `//host`, not `/\host`) and http(s), and any URL with a control character or a space (browsers strip TAB / CR / LF, so `/\t/host` would become `//host`); "See all", host quick actions (template) and configured `quick_actions[].url` (container compilation) are checked the same way; the browser re-checks every URL before rendering and before navigating; recents keep same-site paths only. |
| **Injection** | The kit builds no SQL; providers must use parameters. No shell, no `eval`, no `unserialize`. |
| **Information leaks** | Responses are `Cache-Control: no-store, private` and `X-Robots-Tag: noindex`; error responses carry stable codes, never exception text; a failing provider is logged with its class and the exception class only — never the query (personal data); a row icon that cannot be rendered (unknown name, icon source failure) falls back to the kind icon instead of failing the search. |
| **Abuse / DoS** | Per-user rate limiter (sliding window 30 / 10 s by default, 429), `min_length` / `max_length`, `per_group` rows, browser debounce and request abort. |
| **`min_length` exemption** (`min_length_exempt_patterns`, empty by default; Audit HZ (2026-11-07): Pass (good) / Low, HZ-L1, HZ-I1, HZ-I2 and HZ-I5 addressed before release) | Patterns come from the container configuration, never from the request, and run on user input. Only `min_length` is lifted: role check, rate limit and `max_length` cut run first and are unchanged, and only providers implementing `MinLengthExemptSearchProviderInterface` receive such a query, so the others cannot be made to scan for a fragment. The empty query is never exempt. Patterns outside the subset PCRE and JavaScript read alike, `%env()%` values and patterns that do not compile fail container compilation, so the browser's decision and the server's agree, and the server's is the one that counts. Cost: patterns run only on queries below `min_length` (under 50 characters). PCRE's backtrack limit ends a runaway match on the server (refused, logged without the query); the browser has no limit, so a catastrophic pattern can freeze the host's own users' tab. Hosts must keep them linear ([CONFIGURATION](CONFIGURATION.md#queries-exempt-from-min_length)). |
| **CSRF** | Read-only GET with no side effect; the search field form is never submitted (no token by design, REQ-SEC-005 covers mutations). The 1.8.0 recents revalidation is a POST (it reads only, but carries a body): it requires the `X-CSRF-Token` header (token id `nowo_search_kit_recent`, from the box configuration) and answers 403 `{error: csrf}` otherwise. |
| **Shared devices** | Recents hold titles and paths of pages the user opened from the box, in `localStorage` of that origin only, under a **per-user key** (`storage_key` + an HMAC of the user identifier keyed with `kernel.secret` — the identifier never reaches the browser), so the next account on the same browser does not see them; they survive logout for the same account; `recent.enabled: false` turns them off; "Clear" empties them. |
| **Worker-mode state leaks** | No per-request data in shared services (see [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)); Igor audit in `release-check`. |

## Anti-leak model (1.8.0)

Spec 240 rule: everything respects the viewer's permissions in context, **deny by default**; nothing may leak through
counts, snippets, titles, typed filters, suggestions or recents.

| Channel | Measure |
| --- | --- |
| **Deny by default** | `SearchAccess` lists a registry page, page-source entry, palette action or revalidated recent only when one `SearchAccessCheckerInterface` says `true` and none says `false`. No checker, only `null`, or a checker that throws (logged with class names only) → denied. A host that installs 1.8.0 without a checker therefore gets **no** new rows, never more. |
| **Before counting, ranking and snippets** | The registry checks every candidate **before** it is matched; a denied page is never scored, counted, deduplicated or rendered, and its keywords are never compared (`RegistryActionsRecentTest` asserts the checker saw every candidate). Actions are checked before matching. With `access.filter_all_rows`, every provider row is checked before the total is computed and before ranking. Providers must filter in their query (contract). |
| **`filter_all_rows` is a safety net** | It removes rows a provider should never have returned, but it cannot hide that they existed: a page of the results page or a group that comes back with fewer rows than `per_page` / `per_group` while more pages follow tells how many hidden rows matched (audit SP-L2). Providers must filter in their query; the option only limits the damage of a provider bug. |
| **Counts** | A group total above the number of returned rows is shown only when the provider marks it exact for this viewer (`SearchGroup::$exactTotal`); otherwise the engine caps it. A row removed by the access filter makes the total inexact. Merged groups sum exact totals minus duplicates, or count rows. The results page tabs show the number of returned rows only. |
| **Keywords, snippets, titles** | Keywords (registry, menu, actions) are matched and never sent to the browser; a missing keywords translation never matches its own key. Subtitles of registry rows are the translated breadcrumb. The score and the date of a row never leave the server. |
| **Typed filters** | Only configured keys are parsed (≤ `filters.max`, values ≤ 100 characters). A provider that does not list a key is skipped (deny by default); a filters-only query reaches only filter-aware providers. Providers resolve values for the viewer and answer an unknown and a forbidden value identically; the functional tests compare the full JSON bodies of `project:gamma` (exists, hidden) and `project:nope` (does not exist), `assignee:carol` / `assignee:nobody`, `type:secret` / `type:nothing`. The engine's `type:` filter runs every eligible provider and drops groups afterwards, so `type:<hidden>` costs the same as `type:<unknown>`. |
| **Suggestions** | `GET {prefix}/filters` answers `{values: []}` for an unconfigured key, a key no provider supports, a failing provider and a prefix with no visible value alike; providers return only values the viewer may see; at most `values_limit`. |
| **Recents** | With `recent.revalidate` the browser stores `{url, kind}` only, under the per-user HMAC key; it shows nothing until the server answered. `POST {prefix}/recent` (CSRF header, rate limit, ≤ 32 KB body, ≤ `recent.max` references) keeps a reference only when it is a same-site path of a GET route, the checkers allow it **now**, and a resolver returns a row with the same URL; anything else — unknown, deleted, forbidden, malformed — is dropped the same way, and the browser forgets it. |
| **Scope and context** | Scoped registry entries need a scope and are checked with it. The `from` path of the JSON endpoint is client input: a same-site path cut to 512 characters, matched as a GET route (no redirect, no fetch), and its route and parameters reach the action providers **only when the access checkers allow that page for the viewer** — otherwise the providers get an empty context (audit SP-L1), so an allowed action cannot carry the name of a hidden page's record. A hidden page and a missing page give the same answer. |
| **Actions** | GET links only (`SearchAction` refuses non-path / non-http(s) URLs); the bundle never executes anything. |
| **Results page** | Same guard as the endpoint (guests get the firewall entry point, users without `role` a 403, rate limit → 429), `no-store`, `noindex`, server-rendered without script, every link built by the router. |
| **Timing** | The registry checks every candidate whatever the query (the checker's cost does not depend on whether a page matches); `type:` runs the same providers for hidden and unknown keys; a hidden and an unknown scope run the same code paths in the bundle. Remainder: a provider's own query may take longer when rows exist but are filtered than when nothing exists — providers should filter in the SQL `WHERE` (one query, same plan) rather than fetch then hide; the per-user rate limit bounds any timing probe. |
| **Cost** | Bounded per request (audit SP-M1, delta SPd-L1 / SPd-I1). Matching: needles cut to 64 characters / 8 words, haystacks to 512 characters / 64 words; each query term is folded once (in `SearchQuery`); a registry entry's texts are first folded **together** in one string, and an entry no term can match exactly (every word of a term must occur) and whose words are not within the allowed edits of the fuzzy term is rejected without folding or comparing its texts one by one; otherwise each text is folded once (`FoldedText`); a text that cannot beat the best score is not compared; synonyms ≤ `max_terms` alternatives for texts ≤ 64 characters; fuzzy only on the typed text (edit distance ≤ 2, words ≤ 32 bytes, candidates outside the length window skipped). Registry: ≤ `max_candidates` entries, ≤ 32 keywords per entry and locale (also menu entries and actions; a debug line names a cut list), ≤ 64 keywords of the other locales after deduplication (no further locale is translated once reached), and a ceiling of `PageRegistryProvider::MAX_EVALUATIONS` (100,000) comparisons per search, a fuzzy comparison counting `SearchQuery::FUZZY_COST` (16). **Two passes:** the exact tiers run over every visible entry first; typos only on the remaining budget and only for entries without an exact match, so the ceiling never drops an exact match; entries past the ceiling are not listed (fewer rows, never more; a notice without the query is logged). Measured with `tests/Unit/Everywhere/CostTest.php` (500 entries × 30–32 keywords × 25 terms, fuzzy on and off, plain-text and 7-locale translated entries): **8.5–9.6 s per search at 2660230; 12–65 ms now** without pcov (16–90 ms with pcov loaded, ≤ 134 ms while collecting coverage); the test fails above 200 ms. Actions: ≤ `actions.max_candidates` × 33 texts. The per-user rate limit bounds the number of searches. |
| **Worker mode** | All new services are stateless (constructor-built immutable indexes only); the router context swap used to match a URL as GET during a POST is scoped to one call and restored in `finally`; Igor clean; a kept-alive kernel test alternates viewers. |

## Secrets and cryptography

The bundle ships no secret, stores none and signs nothing. The rate-limiter key is a SHA-256 of the user identifier
(no personal data in the cache).

## Logging

Only failing providers (provider class and exception class) and exempt patterns the engine gave up on (the configured
pattern and the PCRE error) are logged, at error level, channel `nowo_search_kit`. No query text, no user data.

## Dependencies

`composer audit` runs in CI (`Composer audit (Critical/High)` step) and before each release; Dependabot keeps
dependencies current. Security fixes are released as patch versions of the supported line (see
[`.github/SECURITY.md`](../.github/SECURITY.md)).

## Permissions and exposure

| Exposure | Restriction |
| --- | --- |
| `GET /search` | Behind the host's session firewall; the bundle re-checks the user and `role` on every request |
| Pages group | Only menu entries the menu service returns for the current user (DashboardMenuBundle filters by its permission checkers) |
| Provider groups | Exactly what each host provider returns (with `access.filter_all_rows`, also what the access checkers allow) |
| Registry pages, page sources, actions, revalidated recents (1.8.0) | Only what the host's `SearchAccessCheckerInterface` allows (deny by default) |
| `GET {prefix}/results`, `GET {prefix}/filters`, `POST {prefix}/recent` (1.8.0) | 404 until enabled; same user / `role` / rate-limit guard as the endpoint; recents also need the CSRF token |

## Host responsibilities (residual risks)

- **Providers:** check permissions exactly like the target pages; never count hidden records; treat `scope` as untrusted
  (unknown or forbidden → null); bound every query (`LIMIT $query->limit`).
- **Scope resolver:** return a scope only after the access check (the chip shows its label).
- **Access checker (1.8.0):** implement the same rule as your routes' real access control; abstain (`null`) on routes you
  do not know rather than allowing them. Decide for `$request->userIdentifier`, **never for the session token**: a
  search by identifier (`searchFor()`) runs for another user than the HTTP session (audit SP-L3).
- **Action providers (1.8.0):** the `SearchContext` of the JSON endpoint is client-supplied (kept only when the viewer may
  open that page); check access before printing anything read from it.
- **Filter value providers (1.8.0):** honour `$limit` in your query; the bundle cuts the list but reads every value you
  yield first. Typed filters, suggestions and reference resolvers must resolve values for the
  viewer and answer "unknown" and "forbidden" identically ([CONTRACT-240](CONTRACT-240.md)).
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
| Since then | `toggle_icon_template` (1.4.0): independent audit 2026-11-06 (Appendix HE, PR #15 at `b3a8ae2`): **Pass (good) / Info**, no Critical, High, Medium or Low; default output byte-identical to 1.3.1. Residuals (Info): a missing path, or an empty `%env()%` value at runtime, fails the render of signed-in pages with the box (generic 500 in production, nothing about the path in the response); most `%env()%` forms are refused at compilation |
| Motion batch | Batch audit Appendix HW (2026-11-07), at `c9162d3`: Pass (good). HW-L5 fixed at `5813835`; delta audit: **Pass (good)**. |

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
