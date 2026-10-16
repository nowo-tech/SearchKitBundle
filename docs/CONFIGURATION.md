# Configuration

## Table of contents

- [Root key](#root-key)
- [Why no profiles](#why-no-profiles)
- [Full reference](#full-reference)
- [Options](#options)
- [Rate limit](#rate-limit)
- [Quick actions](#quick-actions)
- [Icons](#icons)
- [Pages group](#pages-group)
- [Container parameters](#container-parameters)
- [Timeouts](#timeouts)

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
    icon_set: ux_icon               # ux_icon | svg_inline
    icons:                          # icon key / row kind → UX Icons name, merged over the defaults
        projects: 'tabler:folder'
        issues: 'tabler:bug'
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
| `role` | `IS_AUTHENTICATED` | Security attribute the user needs. Others get no box and 403 from the endpoint (guests 401 / login). |
| `min_length` | `2` | Minimum trimmed length before the providers run. |
| `max_length` | `200` | The query is cut to this many characters (also the input `maxlength`). |
| `per_group` | `5` | Rows per group; a provider returning more is cut (its `total` is kept). |
| `debounce_ms` | `150` | Browser debounce before a request; the previous request is aborted. |
| `keyboard_shortcuts` | `true` | Ctrl/⌘ K and `/`; off also hides the shortcut hint. |
| `recent.*` | on, 8, `nowo.search.recent` | Per-browser recent picks (text only, same-site paths only). |
| `assets.*` | `true`, `true` | Whether the template prints the stylesheet / script tags. |
| `icon_set` | `ux_icon` | `svg_inline` never calls UX Icons (built-in SVG, kind icons for rows). |

## Rate limit

The bundle registers the framework rate limiter `nowo_search_kit` (service `limiter.nowo_search_kit`) from
`rate_limit`, keyed per user (a SHA-256 of the user identifier — no personal data in the cache key). Exceeded:
`429 {"error": "rate_limited"}` with `Cache-Control: no-store, private`; the browser shows `search.rate_limited`. Use a
storage shared by every worker / replica (the framework default `cache.rate_limiter` → configure it on Redis in
production). `token_bucket` refills `limit` tokens per `interval`.

## Quick actions

Each entry needs `label` (translation key or plain text, translated in `translation_domain`, default `messages`) and
**exactly one** of `route` (+ `route_parameters`) or `url` (a path or an http(s) URL). `kind` picks the icon (default
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

`nowo_search_kit.role`, `.min_length`, `.max_length`, `.per_group`, `.icon_set`, `.icons` (merged with the defaults) and
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
