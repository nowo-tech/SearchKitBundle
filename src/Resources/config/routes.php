<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * Thin loader for the bundle's attribute routes (REQ-SF-004). Import it with a prefix, e.g.:
 *
 *     nowo_search_kit:
 *         resource: '@NowoSearchKitBundle/Resources/config/routes.php'
 *         prefix: /search
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import('../../Controller/', 'attribute');
};
