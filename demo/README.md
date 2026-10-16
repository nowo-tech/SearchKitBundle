# Search Kit Bundle — Demo

FrankenPHP demo for **Symfony 8.1** (PHP 8.5) with the header search in the navbar: in-memory users, three demo
providers in `src/Search/` (Projects with per-user access, Documentation, admin-only Users) and a scope resolver for
project pages. No database.

## Accounts

| User | Role | Sees |
| --- | --- | --- |
| `alice` | `ROLE_ADMIN` | Every project (including the private Billing service and Platform API), docs, Users group |
| `bob` | `ROLE_USER` | Public projects (Online shop, Mobile app) and docs only |

Password for both: `password`.

## Quick start

```bash
make -C demo/symfony8 up
```

Default URL: **http://localhost:8157** (`PORT` in `demo/symfony8/.env.example`). Sign in, press Ctrl/⌘ K (or `/`) and
type `shop`, `api`, `doc` or `alice`; open a project page to try the scope chip (Backspace removes it).

## Commands

| Command | Description |
| ------- | ----------- |
| `make -C demo/symfony8 up` | Build, install dependencies, publish assets, build the Vite entry |
| `make -C demo/symfony8 down` | Stop containers |
| `make -C demo/symfony8 test` | Sync the bundle, run the demo PHPUnit tests |
| `make -C demo/symfony8 test-e2e` | Playwright happy path (open, keyboard, permissions, scope, phone) — needs `up` |
| `make -C demo/symfony8 demo-screenshots` | Regenerate `docs/images/demo/overview.webp` (README image) |
| `make demo-smoke` | From the bundle root: boot the demo and assert HTTP 200 (REQ-TEST-011) |
| `make update-deps` | Refresh bundle **and** demo Composer dependencies (from the bundle root) |

From `demo/`: `make up`, `make down`, `make test-symfony8`, `make release-check` (tests + e2e + healthcheck).

See [`docs/DEMO-FRANKENPHP.md`](../docs/DEMO-FRANKENPHP.md) for the FrankenPHP setup (worker / classic, Caddyfile, troubleshooting).
