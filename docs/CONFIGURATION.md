# Configuration

## Table of contents

- [Root key](#root-key)
- [Why no profiles](#why-no-profiles)
- [Full reference](#full-reference)
- [Options](#options)
- [Queries exempt from min_length](#queries-exempt-from-min_length)
- [Rate limit](#rate-limit)
- [Quick actions](#quick-actions)
- [Icons](#icons)
- [Pages group](#pages-group)
- [Container parameters](#container-parameters)
- [Timeouts](#timeouts)
- [FormKit profile](#formkit-profile)

## Root key

All options live under `nowo_search_kit` (`Nowo\SearchKitBundle\DependencyInjection\Configuration::ALIAS`).

## Why no profiles

An application has one header search box, so there is no second variant to name: the tree is flat, without
`default_profile` / `profiles` (REQ-CFG-001 applies "only when multi-config makes sense"). Every key has a safe default.

## Full reference

```yaml
nowo_search_kit:
    role: IS_AUTHENTICATED          # attribute required to see the box and call the endpoint
    min_length: 2                   # shorter queries return no groups (1–50)
    min_length_exempt_patterns: []  # shorter queries matching one still search, opted-in providers only, e.g. ['^#\d+$']
    max_length: 200                 # longer queries are cut (10–1000)
    per_group: 5                    # rows per group = SearchQuery::$limit (1–50)
    debounce_ms: 150                # delay after the last keystroke (0–2000)
    keyboard_shortcuts: true        # Ctrl/⌘ K anywhere, "/" outside fields
    rate_limit:
        enabled: true
        policy: sliding_window      # sliding_window | fixed_window | token_bucket
        limit: 30
        interval: '10 seconds'
    recent:
        enabled: true
        max: 8
        storage_key: nowo.search.recent   # localStorage key ([A-Za-z0-9._:-])
    quick_actions:                  # rows while the query is empty (after the recents)
        - { label: 'nav.dashboard', route: dashboard_home }
        - { label: 'nav.account', route: account_profile, kind: action }
        - { label: 'Admin', url: /admin, role: ROLE_ADMIN, translation_domain: messages }
    assets:
        stylesheet: true            # nowo-search.css + lazy nowo-search-popover.css
        script: true                # nowo-search.js (IIFE)
        popover_stylesheet: ~       # null: bundle file when stylesheet is on | false: none | '/build/x.css' (1.0.2)
    icon_set: ux_icon               # ux_icon | svg_inline
    icons:                          # icon key / row kind → UX Icons name, merged over the defaults
        projects: 'tabler:folder'
        issues: 'tabler:bug'
    toggle_icon_template: ~         # Twig partial rendered in the toggle instead of the three state icons
    menu_pages:
        enabled: null               # null: on when DashboardMenuBundle is installed and menus is not empty
        service: 'Nowo\DashboardMenuBundle\Twig\MenuExtension'
        menus:
            - { code: dashboard, label: 'nav.dashboard', translation_domain: messages }
            - admin                 # a plain code: no breadcrumb label
        icon: 'tabler:file'
        priority: 100
        skip_when_scoped: true
```

## Options

| Key | Default | Description |
| --- | --- | --- |
| `role` | `IS_AUTHENTICATED` | Security attribute the user needs. Others get no box and JSON 403 from the endpoint (guests JSON 401). |
| `min_length` | `2` | Minimum trimmed length before the providers run. |
| `min_length_exempt_patterns` | `[]` | Regular expressions for queries that search below `min_length`, on the providers that opted in. See [below](#queries-exempt-from-min_length). |
| `max_length` | `200` | The query is cut to this many characters (also the input `maxlength`). |
| `per_group` | `5` | Rows per group; a provider returning more is cut (its `total` is kept). |
| `debounce_ms` | `150` | Browser debounce before a request; the previous request is aborted. |
| `keyboard_shortcuts` | `true` | Ctrl/⌘ K and `/`; off also hides the shortcut hint. |
| `recent.*` | on, 8, `nowo.search.recent` | Per-browser recent picks (text only, same-site paths only), stored under `storage_key` + `.` + a 16-character HMAC of the user identifier (keyed with `kernel.secret`), so each account on a shared browser has its own list. |
| `assets.stylesheet`, `assets.script` | `true`, `true` | Whether the template prints the stylesheet / script tags. |
| `assets.popover_stylesheet` | `null` | Lazy dropdown stylesheet injected on the first hover / focus: `null` = the bundle file when `assets.stylesheet` is on (none otherwise), `false` = none, a path or http(s) URL = that file (e.g. your Vite chunk). The JS option `loadPopoverStyles` takes precedence (see USAGE). (1.0.2) |
| `icon_set` | `ux_icon` | `svg_inline` never calls UX Icons (built-in SVG, kind icons for rows). |
| `toggle_icon_template` | `null` | Twig partial rendered inside `nowo_search_toggle_icon` instead of the three state icons, e.g. a morphing icon. `null` keeps those icons; an empty or non-string literal fails container compilation; a path that does not exist fails the render. `%env(X)%` needs an `env(X)` parameter default (a valid path or `~`), or compilation refuses it, and prefixed forms such as `default::` and `string:` are refused; the value read at runtime is not validated, so an empty one fails the render like a missing path. See [USAGE](USAGE.md#morphing-toggle-icon). |

## Queries exempt from min_length

```yaml
nowo_search_kit:
    min_length: 3
    min_length_exempt_patterns: ['^#\d+$']     # "#7" searches; "ab" does not
```

```php
final class TaskNumberProvider implements MinLengthExemptSearchProviderInterface
{
    public function search(SearchQuery $query): ?SearchGroup
    {
        // true: the text is below min_length. Run the exact lookup, not the LIKE.
        if ($query->belowMinLength) {
            // …
        }
    }
}
```

**Both halves are needed.** A query shorter than `min_length` that matches a pattern (after trimming; the empty query
never does) is sent by the browser and accepted by `SearchEngine`, but it reaches only the providers implementing
`MinLengthExemptSearchProviderInterface`, with `SearchQuery::$belowMinLength` true. Every other provider keeps never
seeing such a query. With patterns and no opted-in provider, the box answers "no results". A query at `min_length` or
longer is not affected: it reaches every provider, whether or not it matches, with `$belowMinLength` false. The
interface is read with `instanceof` on the tagged service, so a decorator of the provider has to implement it too.

**Only `min_length` is lifted.** The role check, the rate limit (each exempt query counts) and the `max_length` cut run
before the engine and do not change. Providers still return only what the user may open.

**The dialect.** The same source runs in PHP and in the browser, so it is written without delimiters or flags and must
stay in the subset both engines read alike:

| Allowed | Refused, because |
| --- | --- |
| Literal characters (non-ASCII too) | Control characters; an unescaped `]` or `}` (a syntax error in JavaScript's `u` mode) |
| `\d \D \w \W \b \B`, always ASCII | `\s \S` and `.`: the two engines disagree on Unicode spaces and line breaks |
| Escaped `^ $ \ . * + ? ( ) [ ] { } \| /` | Any other escape: `\#`, `\A`, `\z`, `\Q`, `\1`, `\x{…}`, `\u{…}`, `\p{…}` |
| `[abc]`, `[^a-z0-9_]`, `\-` inside a class | `[]`, `[^]`, `[[:alpha:]]` and any unescaped `[` inside a class |
| `^`, `$`, `\|`, `(…)`, `(?:…)` | Lookaround, named or atomic groups, `(?i)` and other inline flags, verbs such as `(*UCP)` |
| `* + ? {n} {n,} {n,m}`, also lazy | Possessive (`a++`) and stacked (`a{2}{3}`) quantifiers, `{,n}` |

Matching is case-sensitive, and code points count as one character on both sides. As everywhere in Symfony
configuration, write a literal `%` as `%%`. `$` is the very end of the query in
both (no final-newline exception). Anchor your patterns: `#\d+` also matches `x#7`. A pattern outside the subset, a
non-string, an `%env()%` value (it would escape the check) or a pattern that does not compile fails container
compilation naming the entry and the reason, and never exempts or disables anything. Nothing is translated between
dialects: `MinLengthExemptPattern` is the only gate, and `tests/Fixtures/min-length-exempt-patterns.json` is run through
both engines in CI.

**The cost.** Patterns come from your configuration, but they run on user input: on the server for every query below
`min_length`, and in each browser on each debounced keystroke below it. They are never evaluated on a query at
`min_length` or longer, so the text is at most 49 characters, but 49 characters are plenty for catastrophic backtracking.
Keep them linear: anchored, and no quantified group containing a quantifier (`(a+)+`). On the server PCRE's backtrack
limit stops a runaway match, the query stays refused and the error is logged (pattern and PCRE error, never the query).
The browser has no such limit, so a bad pattern can freeze the tab. The patterns are also published to the browser in
`data-nowo-search-config`, for users granted `role`: do not put anything confidential in them.

## Rate limit

The bundle registers the framework rate limiter `nowo_search_kit` (service `limiter.nowo_search_kit`) from
`rate_limit`, keyed per user (a SHA-256 of the user identifier — no personal data in the cache key). Exceeded:
`429 {"error": "rate_limited"}` with `Cache-Control: no-store, private`; the browser shows `search.rate_limited`. Use a
storage shared by every worker / replica (the framework default `cache.rate_limiter` → configure it on Redis in
production). `token_bucket` refills `limit` tokens per `interval`.

## Quick actions

Each entry needs `label` (translation key or plain text, translated in `translation_domain`, default `messages`) and
**exactly one** of `route` (+ `route_parameters`) or `url` (a path or an http(s) URL; anything else fails container
compilation). `kind` picks the icon (default
`action`); `role` hides it from users without that attribute. Hosts can pass more actions from Twig (listed first):

```twig
{{ include('@NowoSearchKitBundle/search.html.twig', {nowo_search_actions: [
    {title: 'Issues in Shop', url: path('issue_index', {id: project.id}), kind: 'issues'},
]}) }}
```

## Icons

Defaults (`Configuration::DEFAULT_ICONS`): `search` `tabler:search`, `close` `tabler:x`, `loading` `tabler:loader-2`,
`pages` `tabler:file`, `recent` `tabler:history`, `action` `tabler:arrow-right`, `item` `tabler:point`. Add one key per
group key (it is the default row kind) — e.g. `projects: 'tabler:folder'`. Every key becomes a `<template
data-nowo-search-icon="…">` used for rows without their own icon (recents, actions, rows whose icon is unknown).

## Pages group

`menu_pages` registers the built-in `MenuPagesProvider` (group key `pages`): it walks each menu code through
`service` — any service with `getMenuTree(string $code)` and `getHref(object $item, int $referenceType)`, by default
DashboardMenuBundle's Twig extension (permission-filtered tree) — and lists the entries whose label contains the query,
with a breadcrumb (`label` › parents) and absolute paths, deduplicated by URL. `enabled: true` forces it (container error
when the service is missing), `false` turns it off.

## Container parameters

`nowo_search_kit.role`, `.min_length`, `.min_length_exempt_patterns`, `.max_length`, `.per_group`, `.icon_set`, `.toggle_icon_template`, `.icons` (merged with the defaults) and
`.settings` (the browser-facing subset used by the Twig extension).

## Timeouts

The bundle itself opens no subprocess and no outbound HTTP connection and has no timeout option of its own
(REQ-RUNTIME-001). The time of a search is the time of your providers' queries: keep them bounded (`LIMIT`
`$query->limit`, indexed columns, a capped `COUNT`) and below PHP's `max_execution_time`, itself below the web server's
write timeout:

| Layer (innermost first) | Setting | Demo value |
| --- | --- | --- |
| Provider queries | your repository (`LIMIT`, indexes, DB `max_execution_time` hint) | in memory |
| PHP | `max_execution_time` | 30 s |
| FrankenPHP / Caddy | `servers { timeouts { write … } }`; `frankenphp { max_wait_time … }` | write 60 s; max_wait_time 30 s |

## FormKit profile

`SearchFieldType` is a FormKit Type (`#[FormKitConfig('search_kit')]`, REQ-TWIG-005 rule 11). Its field defaults come
from the FormKit profile **`nowo_form_kit.profiles.search_kit`**, which the bundle prepends unless you define it:

```yaml
# config/packages/nowo_form_kit.yaml — define the profile to override the bundle default (nothing is prepended then)
nowo_form_kit:
    profiles:
        search_kit:
            alias: search_kit
            translation_domain: NowoSearchKitBundle
            auto_help: false
            auto_placeholder: false
            defaults:
                attr: {}
                row_attr: {}
            field_types: {}
```

The type's own options (`nowo-search__input`, `data-part`, `label: false`) win over the profile; UiKit theme slots
(`theme.bundles.NowoSearchKitBundle`) still add host classes. The profile does not change your
`nowo_form_kit.default_profile`.

