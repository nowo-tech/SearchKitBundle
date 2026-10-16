# Installation

## Table of contents

- [Requirements](#requirements)
- [Install with Composer](#install-with-composer)
- [Register the bundle (without Flex)](#register-the-bundle-without-flex)
- [Configuration file](#configuration-file)
- [Routes](#routes)
- [Providers](#providers)
- [Render the search box](#render-the-search-box)
- [Publish assets](#publish-assets)
- [Icons](#icons)
- [Pages group (DashboardMenuBundle)](#pages-group-dashboardmenubundle)
- [FrankenPHP](#frankenphp)

## Requirements

- PHP `>=8.3 <8.6`. The floor is **8.3**, above the Nowo minimum of 8.2 (REQ-SF-001), because the bundle uses
  **typed class constants** (`public const string ALIAS = 'nowo_search_kit';`), a PHP 8.3 feature that is a parse error
  on 8.2. Rector (`withPhpVersion(PHP_83)`) targets the same floor.
- Symfony 7.4, 8.0 or 8.1+: FrameworkBundle, SecurityBundle, TwigBundle, Form, Asset, RateLimiter.
- Twig Extra (`twig/extra-bundle`, `twig/string-extra`) — installed as dependencies; register
  `Twig\Extra\TwigExtraBundle\TwigExtraBundle` when Flex does not.
- Optional: `symfony/ux-icons` (icons from your icon set), `nowo-tech/dashboard-menu-bundle` (built-in Pages group).

## Install with Composer

```bash
composer require nowo-tech/search-kit-bundle
```

With **Symfony Flex**, the recipe registers the bundle and copies `config/packages/nowo_search_kit.yaml` and
`config/routes/nowo_search_kit.yaml`.

## Register the bundle (without Flex)

```php
// config/bundles.php
return [
    // ...
    Nowo\SearchKitBundle\NowoSearchKitBundle::class => ['all' => true],
];
```

## Configuration file

Every key is optional — an empty file (or none) gives a working, rate-limited endpoint for signed-in users:

```yaml
# config/packages/nowo_search_kit.yaml
nowo_search_kit:
    role: IS_AUTHENTICATED
```

See [CONFIGURATION.md](CONFIGURATION.md) for the full reference.

## Routes

```yaml
# config/routes/nowo_search_kit.yaml
nowo_search_kit:
    resource: '@NowoSearchKitBundle/Resources/config/routes.php'
    prefix: /search
    trailing_slash_on_root: false
```

The endpoint is `GET /search?q=…&scope=…` (route `nowo_search_kit_search`). `trailing_slash_on_root: false` keeps the
path `/search` (without it the route is `/search/`; both work, the template always uses the generated URL). The
controller checks the signed-in user itself (guests get 401 / your login redirect); you can also cover `^/search` in
`access_control`.

## Providers

Results come only from your services implementing `Nowo\SearchKitBundle\Contract\SearchProviderInterface`
(autoconfigured with the tag `nowo_search_kit.provider`). Without a provider (and without the Pages group) the search
box only shows recents and quick actions. See [USAGE.md](USAGE.md#providers).

## Render the search box

Where your header icons live, in the layout of signed-in pages:

```twig
{{ include('@NowoSearchKitBundle/search.html.twig') }}
```

Guests and users without `role` get nothing. The template loads `css/nowo-search.css` and `js/nowo-search.js` from the
`nowo_search_kit` asset package (mapped to `/bundles/nowosearchkit`); the dropdown stylesheet
`css/nowo-search-popover.css` is injected on the first hover / focus. The exact tags the template emits — use the same
ones if you load the assets yourself (`assets.stylesheet: false` / `assets.script: false`):

```twig
<link rel="stylesheet" href="{{ asset('css/nowo-search.css', 'nowo_search_kit') }}">
<script src="{{ asset('js/nowo-search.js', 'nowo_search_kit') }}" defer></script>
```

## Publish assets

```bash
php bin/console assets:install
```

## Icons

With `icon_set: ux_icon` (default) and `symfony/ux-icons` installed, icons come from your icon set (Tabler names by
default). Import them so they are served locally (no Iconify request at runtime):

```bash
php bin/console ux:icons:import tabler:search tabler:x tabler:loader-2 tabler:file tabler:history tabler:arrow-right tabler:point
```

Add the icons your providers name (e.g. `tabler:folder`, `tabler:bug`). An icon UX Icons cannot find falls back to the
built-in SVG (toggle and kind icons) or to the kind icon (row icons); `icon_set: svg_inline` uses only the built-in set.

## Pages group (DashboardMenuBundle)

When `nowo-tech/dashboard-menu-bundle` is installed, list the menu codes to search:

```yaml
nowo_search_kit:
    menu_pages:
        menus:
            - { code: dashboard, label: 'nav.dashboard' }
            - { code: admin, label: 'nav.admin' }
```

## FrankenPHP

The bundle is designed for FrankenPHP worker mode: its services are stateless. See
[FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md) and the demo in [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md).
