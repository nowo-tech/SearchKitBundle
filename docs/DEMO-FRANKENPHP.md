# Demo applications with FrankenPHP (development and production)

This document describes how the **Search Kit Bundle** demo runs under **FrankenPHP** in Docker.

## Table of contents

- [Overview](#overview)
- [What the demo includes](#what-the-demo-includes)
- [Development configuration](#development-configuration)
  - [Caddyfile (development)](#caddyfile-development)
  - [PHP and Twig (development)](#php-and-twig-development)
  - [Start (development)](#start-development)
- [Production configuration](#production-configuration)
- [Switching classic vs worker (`FRANKENPHP_MODE`)](#switching-classic-vs-worker-frankenphp_mode)
- [Timeouts](#timeouts)
- [Troubleshooting](#troubleshooting)
  - [Twig or PHP changes do not appear](#twig-or-php-changes-do-not-appear)
  - [Demo does not start](#demo-does-not-start)
  - [Browser console: 404 on `/build/…` or missing Vite entry](#browser-console-404-on-build-or-missing-vite-entry)
  - [Browser console: 404 on `nowo-search.js`](#browser-console-404-on-nowo-searchjs)
  - [No results](#no-results)

## Overview

The `demo/` folder is **not shipped** in the Composer package (`archive.exclude` includes `/demo`). The demo exists only in the source repository.

The stack uses:

- **FrankenPHP** (Caddy + PHP) in a single `php` container
- **No database container** (in-memory users and search data; REQ-DEMO-006 / REQ-DEMO-011 not applicable)
- Bundle mount `../..` → `/var/search-kit-bundle`
- Two Caddyfiles: `Caddyfile` (worker) and `Caddyfile.dev` (classic)
- `FRANKENPHP_MODE` (`classic` \| `worker`, default **`worker`**)

| Demo | UI | Default port |
| --- | --- | --- |
| `demo/symfony8/` | Bootstrap 5 | **8157** |

```bash
make -C demo up-symfony8
# Demo started at: http://localhost:8157
```

| Aspect | Development (default) | Production |
| --- | --- | --- |
| FrankenPHP worker | Off when `FRANKENPHP_MODE=classic` | On (`worker`) |
| Twig cache | Off (`config/packages/dev/twig.yaml`) | On |
| OPcache revalidation | Every request (`docker/php-dev.ini`) | Default |
| HTTP cache headers | `no-store` in `Caddyfile.dev` | Omitted |
| `APP_ENV` / `APP_DEBUG` | `dev` / `1` | `prod` / `0` |
| Database | None (in-memory users and search data) | — |

## What the demo includes

- **Symfony Web Profiler** — `dev` and `test`
- **Nowo Twig Inspector** and **Nowo Hot Reload** — `dev`/`test` only (Caddyfile Mercure hub + `hot_reload`; do not enable Hot Reload in production)
- **Twig Extra Bundle** (`twig/extra-bundle` + `twig/string-extra`)
- **Search Kit Bundle** — Symfony 8.1 (PHP 8.5) app with the header search in the navbar: in-memory users `alice` (admin) and `bob` (password `password`), three demo providers (`src/Search/`: Projects with per-user access, Documentation, admin-only Users) and a scope resolver for project pages. See [`demo/README.md`](../demo/README.md).
- **Tests**: `make -C demo/symfony8 test` (PHPUnit), `make -C demo/symfony8 test-e2e` (Playwright: open, keyboard, permissions, scope, phone), `make -C demo/symfony8 demo-screenshots` (README image), `make demo-smoke` from the bundle root (boot + HTTP 200, REQ-TEST-011).
- **PHP / Symfony policy**: the demo tracks the latest PHP (8.5) and Symfony 8.1; the bundle itself supports PHP 8.3+ and Symfony 7.4 / 8.x (see the README requirements).
- **The search box as an integrator ships it** — `templates/base.html.twig` includes `@NowoSearchKitBundle/search.html.twig`, which loads the bundle's IIFE `js/nowo-search.js` and `css/nowo-search.css` from `/bundles/nowosearchkit/` (`assets:install`)
- **Pentatrion Vite + pnpm** — only for the demo's own entry `assets/app.ts` (demo chrome); it does not compile the bundle TypeScript
- **No database** — REQ-DEMO-011 does not apply (no `*.db` files either)

Example `config/bundles.php` (abridged: the demo also registers Pentatrion Vite, WebProfiler and Debug):

```php
return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Twig\Extra\TwigExtraBundle\TwigExtraBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
    Symfony\UX\Icons\UXIconsBundle::class => ['all' => true],
    Nowo\SearchKitBundle\NowoSearchKitBundle::class => ['all' => true],
    Nowo\HotReloadBundle\NowoHotReloadBundle::class => ['dev' => true, 'test' => true],
    Nowo\TwigInspectorBundle\NowoTwigInspectorBundle::class => ['dev' => true, 'test' => true],
];
```

## Development configuration

### Caddyfile (development)

`demo/symfony8/docker/frankenphp/Caddyfile.dev` — no worker, `no-store` cache headers, the Mercure hub used by Hot Reload.

### PHP and Twig (development)

`docker/php-dev.ini` revalidates OPcache. `config/packages/dev/twig.yaml` sets `twig.cache: false`.

### Start (development)

From the bundle root:

```bash
make -C demo up
```

`make up` in `demo/symfony8` prints `Waiting for container to be ready...`, `Installing dependencies...`, then `Demo started at: http://localhost:<PORT>` from `PORT` in `.env` / `.env.example` (REQ-DEMO-005).

## Production configuration

Use `FRANKENPHP_MODE=worker` (default) and `APP_ENV=prod`. Do not enable Hot Reload.

## Switching classic vs worker (`FRANKENPHP_MODE`)

Set `FRANKENPHP_MODE=classic` or `worker` in `.env`, then recreate:

```bash
docker compose up -d --force-recreate
```

The entrypoint copies `Caddyfile.dev` when mode is `classic`.

## Timeouts

The bundle opens no outbound connection; a search costs what the providers' queries cost (in memory in the demo). See
[CONFIGURATION — Timeouts](CONFIGURATION.md#timeouts). The demo layers its deadlines innermost first (REQ-RUNTIME-001):

| Layer | Where in the demo | Value |
| --- | --- | --- |
| Outbound HTTP (none in the bundle) | `framework.http_client.default_options.timeout` (`config/packages/framework.yaml`) | 5 s |
| PHP `max_execution_time` (and `max_input_time`) | `docker/php-dev.ini` | 30 s |
| Caddy / FrankenPHP write timeout | `servers { timeouts { write 60s } }` in `docker/frankenphp/Caddyfile` and `Caddyfile.dev` | 60 s |
| FrankenPHP `max_wait_time` | `frankenphp { max_wait_time 30s }` in both Caddyfiles | 30 s (wait for a free PHP thread, then 504) |

**HTTP client 5 s < PHP 30 s < Caddy write 60 s.** Raise them together if you change one.

## Troubleshooting

### Twig or PHP changes do not appear

Use `FRANKENPHP_MODE=classic`, confirm `twig.cache: false` in `dev`, and hard-refresh the browser.

### Demo does not start

Ensure Docker is running, port **8157** is free, and `make -C demo/symfony8 logs` shows FrankenPHP listening. Composer DNS: Compose sets `8.8.8.8` (REQ-DEMO-009).

### Browser console: 404 on `/build/…` or missing Vite entry

Pentatrion needs `public/build/entrypoints.json`. Run `make -C demo/symfony8 assets` (pnpm + `vite build`; also part of `make up` and `update-bundle`). Hard-refresh the page. Caddy serves `/build/*` as static files (no Vite dev-server proxy).

### Browser console: 404 on `nowo-search.js`

The search box runs from the bundle's IIFE: `base.html.twig` includes `@NowoSearchKitBundle/search.html.twig`, which loads `/bundles/nowosearchkit/js/nowo-search.js` (asset package `nowo_search_kit`). Pentatrion (`vite_entry_script_tags('app')`) only serves the demo's own `assets/app.ts`. A 404 means the public assets are not installed: run `make -C demo/symfony8 assets-install` (`assets:install public --symlink --relative`, also part of `make install`). If the file itself is stale, rebuild it from the bundle root with `make assets`.

### No results

Sign in first (guests get no search box). `bob` only sees public projects; type at least two characters.
