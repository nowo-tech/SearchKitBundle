<?php

declare(strict_types=1);

use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Contract\SearchScopeResolverInterface;
use Nowo\SearchKitBundle\Controller\SearchController;
use Nowo\SearchKitBundle\DependencyInjection\NowoSearchKitExtension;
use Nowo\SearchKitBundle\Form\SearchFieldType;
use Nowo\SearchKitBundle\Icon\SearchIcons;
use Nowo\SearchKitBundle\Scope\NullScopeResolver;
use Nowo\SearchKitBundle\Search\SearchEngine;
use Nowo\SearchKitBundle\Twig\SearchKitExtension;
use Nowo\SearchKitBundle\Twig\SearchKitThemeExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\UX\Icons\IconRendererInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * Service definitions for NowoSearchKitBundle. The application adds SearchProviderInterface services (autoconfigured)
 * and may alias SearchScopeResolverInterface to its own resolver.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->private();

    $services->set(NullScopeResolver::class);
    $services->alias(SearchScopeResolverInterface::class, NullScopeResolver::class);

    $services->set(SearchIcons::class)
        ->args([
            param('nowo_search_kit.icon_set'),
            param('nowo_search_kit.icons'),
            service(IconRendererInterface::class)->nullOnInvalid(),
        ]);

    $services->set(SearchEngine::class)
        ->args([
            tagged_iterator(SearchProviderInterface::TAG),
            param('nowo_search_kit.min_length'),
            param('nowo_search_kit.per_group'),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'nowo_search_kit']);

    // Routes stay opt-in (REQ-SF-004 rule 7): not autoconfigured, so the #[Route] class never gets the
    // `routing.controller` tag and a host `routing.controllers` import does not mount it without the prefix.
    $services->set(SearchController::class)
        ->public()
        ->autoconfigure(false)
        ->tag('controller.service_arguments')
        ->arg('$role', param('nowo_search_kit.role'))
        ->arg('$maxLength', param('nowo_search_kit.max_length'))
        ->arg('$limiter', service('limiter.' . NowoSearchKitExtension::LIMITER)->nullOnInvalid());

    $services->set(SearchFieldType::class);

    // Optional UiKit theme (REQ-UI-003): null when nowo-tech/ui-kit-bundle is not installed.
    $services->set(SearchKitThemeExtension::class)
        ->args([service('nowo_ui_kit.theme')->nullOnInvalid()]);

    $services->set(SearchKitExtension::class)
        ->arg('$packages', service('assets.packages'))
        ->arg('$settings', param('nowo_search_kit.settings'))
        ->arg('$secret', param('kernel.secret'));
};
