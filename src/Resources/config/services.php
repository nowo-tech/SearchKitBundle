<?php

declare(strict_types=1);

use Nowo\SearchKitBundle\Contract\SearchAccessCheckerInterface;
use Nowo\SearchKitBundle\Contract\SearchActionProviderInterface;
use Nowo\SearchKitBundle\Contract\SearchContextScopeResolverInterface;
use Nowo\SearchKitBundle\Contract\SearchEngineInterface;
use Nowo\SearchKitBundle\Contract\SearchFilterValuesProviderInterface;
use Nowo\SearchKitBundle\Contract\SearchPageSourceInterface;
use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Contract\SearchReferenceResolverInterface;
use Nowo\SearchKitBundle\Contract\SearchScopeResolverInterface;
use Nowo\SearchKitBundle\Contract\SearchSynonymProviderInterface;
use Nowo\SearchKitBundle\Controller\FilterValuesController;
use Nowo\SearchKitBundle\Controller\RecentController;
use Nowo\SearchKitBundle\Controller\ResultsController;
use Nowo\SearchKitBundle\Controller\SearchController;
use Nowo\SearchKitBundle\DependencyInjection\NowoSearchKitExtension;
use Nowo\SearchKitBundle\Form\SearchFieldType;
use Nowo\SearchKitBundle\Http\EndpointGuard;
use Nowo\SearchKitBundle\Icon\SearchIcons;
use Nowo\SearchKitBundle\Scope\NullScopeResolver;
use Nowo\SearchKitBundle\Search\Access\SearchAccess;
use Nowo\SearchKitBundle\Search\Action\ActionCollector;
use Nowo\SearchKitBundle\Search\Filter\SearchFilterParser;
use Nowo\SearchKitBundle\Search\Page\PageRegistryProvider;
use Nowo\SearchKitBundle\Search\Ranking\SearchRanker;
use Nowo\SearchKitBundle\Search\Recent\RecentResolver;
use Nowo\SearchKitBundle\Search\SearchEngine;
use Nowo\SearchKitBundle\Search\Synonym\SynonymExpander;
use Nowo\SearchKitBundle\Twig\SearchKitExtension;
use Nowo\SearchKitBundle\Twig\SearchKitThemeExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\UX\Icons\IconRendererInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * Service definitions for NowoSearchKitBundle. The application adds SearchProviderInterface services (autoconfigured)
 * and may alias SearchContextScopeResolverInterface (or the deprecated SearchScopeResolverInterface) to its own resolver.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->private();

    $services->set(NullScopeResolver::class);
    // Both resolver ports default to the null resolver; a host aliases the one its resolver implements.
    $services->alias(SearchContextScopeResolverInterface::class, NullScopeResolver::class);
    $services->alias(SearchScopeResolverInterface::class, NullScopeResolver::class);

    $services->set(SearchIcons::class)
        ->args([
            param('nowo_search_kit.icon_set'),
            param('nowo_search_kit.icons'),
            service(IconRendererInterface::class)->nullOnInvalid(),
        ]);

    // 1.8.0 (spec 240): access checkers (deny by default), filters, synonyms, actions, ranking, registry, recents.
    $services->set(SearchAccess::class)
        ->args([tagged_iterator(SearchAccessCheckerInterface::ACCESS_CHECKER_TAG), service('router')->nullOnInvalid(), service('logger')->nullOnInvalid()])
        ->tag('monolog.logger', ['channel' => 'nowo_search_kit']);

    $services->set(SearchFilterParser::class)
        ->args([param('nowo_search_kit.filters.keys'), param('nowo_search_kit.filters.max')]);

    $services->set(SynonymExpander::class)
        ->args([
            tagged_iterator(SearchSynonymProviderInterface::SYNONYM_PROVIDER_TAG),
            param('nowo_search_kit.synonyms.locales'),
            param('nowo_search_kit.synonyms.max_terms'),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'nowo_search_kit']);

    $services->set(SearchRanker::class)
        ->args([param('nowo_search_kit.ranking.recency_weight'), param('nowo_search_kit.ranking.recency_half_life_days')]);

    $services->set(ActionCollector::class)
        ->args([
            tagged_iterator(SearchActionProviderInterface::ACTION_PROVIDER_TAG),
            service(SearchAccess::class),
            param('nowo_search_kit.quick_actions'),
            service('router'),
            service('translator'),
            service('security.helper')->nullOnInvalid(),
            param('nowo_search_kit.actions.max_candidates'),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'nowo_search_kit']);

    $services->set(PageRegistryProvider::class)
        ->args([
            param('nowo_search_kit.pages'),
            tagged_iterator(SearchPageSourceInterface::PAGE_SOURCE_TAG),
            service(SearchAccess::class),
            service('router'),
            service('translator'),
            param('nowo_search_kit.page_registry.locales'),
            param('nowo_search_kit.page_registry.max_candidates'),
            param('nowo_search_kit.page_registry.icon'),
            service('logger')->nullOnInvalid(),
        ])
        ->autoconfigure(false)
        // The provider tag (priority `page_registry.priority`) is added by NowoSearchKitExtension.
        ->tag(SearchReferenceResolverInterface::REFERENCE_RESOLVER_TAG, ['priority' => 100])
        ->tag('monolog.logger', ['channel' => 'nowo_search_kit']);

    $services->set(RecentResolver::class)
        ->args([service(SearchAccess::class), tagged_iterator(SearchReferenceResolverInterface::REFERENCE_RESOLVER_TAG), service('logger')->nullOnInvalid()])
        ->tag('monolog.logger', ['channel' => 'nowo_search_kit']);

    $services->set(SearchEngine::class)
        ->args([
            tagged_iterator(SearchProviderInterface::TAG),
            param('nowo_search_kit.min_length'),
            param('nowo_search_kit.per_group'),
            service('logger')->nullOnInvalid(),
            param('nowo_search_kit.min_length_exempt_patterns'),
            service(SearchFilterParser::class),
            service(SynonymExpander::class), // replaced by null when synonyms.enabled is false
            service(ActionCollector::class),
            service(SearchAccess::class),
            service(SearchRanker::class),
            param('nowo_search_kit.engine_options'),
        ])
        ->tag('monolog.logger', ['channel' => 'nowo_search_kit']);
    $services->alias(SearchEngineInterface::class, SearchEngine::class);

    $services->set(EndpointGuard::class)
        ->args([service('security.helper'), param('nowo_search_kit.role'), service('limiter.' . NowoSearchKitExtension::LIMITER)->nullOnInvalid()]);

    // Routes stay opt-in (REQ-SF-004 rule 7): not autoconfigured, so the #[Route] class never gets the
    // `routing.controller` tag and a host `routing.controllers` import does not mount it without the prefix.
    $services->set(SearchController::class)
        ->public()
        ->autoconfigure(false)
        ->tag('controller.service_arguments')
        ->arg('$role', param('nowo_search_kit.role'))
        ->arg('$maxLength', param('nowo_search_kit.max_length'))
        ->arg('$limiter', service('limiter.' . NowoSearchKitExtension::LIMITER)->nullOnInvalid())
        ->arg('$access', service(SearchAccess::class));

    $services->set(ResultsController::class)
        ->public()
        ->autoconfigure(false)
        ->tag('controller.service_arguments')
        ->arg('$urls', service('router'))
        ->arg('$settings', param('nowo_search_kit.results_page'))
        ->arg('$role', param('nowo_search_kit.role'))
        ->arg('$maxLength', param('nowo_search_kit.max_length'));

    $services->set(RecentController::class)
        ->public()
        ->autoconfigure(false)
        ->tag('controller.service_arguments')
        ->arg('$enabled', param('nowo_search_kit.recent.revalidate'))
        ->arg('$max', param('nowo_search_kit.recent.max'))
        ->arg('$csrf', service('security.csrf.token_manager')->nullOnInvalid());

    $services->set(FilterValuesController::class)
        ->public()
        ->autoconfigure(false)
        ->tag('controller.service_arguments')
        ->arg('$providers', tagged_iterator(SearchFilterValuesProviderInterface::FILTER_VALUES_TAG))
        ->arg('$keys', param('nowo_search_kit.filters.keys'))
        ->arg('$limit', param('nowo_search_kit.filters.values_limit'))
        ->arg('$logger', service('logger')->nullOnInvalid())
        ->tag('monolog.logger', ['channel' => 'nowo_search_kit']);

    $services->set(SearchFieldType::class);

    // Optional UiKit theme (REQ-UI-003): null when nowo-tech/ui-kit-bundle is not installed.
    $services->set(SearchKitThemeExtension::class)
        ->args([service('nowo_ui_kit.theme')->nullOnInvalid(), param('nowo_search_kit.toggle_icon_template')]);

    $services->set(SearchKitExtension::class)
        ->arg('$packages', service('assets.packages'))
        ->arg('$settings', param('nowo_search_kit.settings'))
        ->arg('$secret', param('kernel.secret'))
        ->arg('$contextScopes', service(SearchContextScopeResolverInterface::class))
        ->arg('$actions', service(ActionCollector::class))
        ->arg('$csrf', service('security.csrf.token_manager')->nullOnInvalid())
        ->arg('$features', param('nowo_search_kit.features'));
};
