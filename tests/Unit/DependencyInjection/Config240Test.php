<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\DependencyInjection;

use Nowo\SearchKitBundle\Contract\SearchSynonymProviderInterface;
use Nowo\SearchKitBundle\DependencyInjection\Configuration;
use Nowo\SearchKitBundle\DependencyInjection\NowoSearchKitExtension;
use Nowo\SearchKitBundle\Search\Page\PageRegistryProvider;
use Nowo\SearchKitBundle\Search\SearchEngine;
use Nowo\SearchKitBundle\Search\Synonym\ConceptGroupSynonymProvider;
use Nowo\SearchKitBundle\Search\Synonym\SynonymExpander;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The 1.8.0 configuration keys: defaults that keep a 1.7 host unchanged, validation, and the wiring.
 */
final class Config240Test extends TestCase
{
    public function testDefaultsKeepA17HostUnchanged(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), []);
        self::assertSame([], $config['pages']);
        self::assertSame(['priority' => 90, 'locales' => Configuration::LOCALES, 'max_candidates' => 500, 'icon' => 'tabler:file'], $config['page_registry']);
        self::assertSame(['filter_all_rows' => false], $config['access']);
        self::assertSame(['prefix' => '>', 'in_results' => true, 'max_candidates' => 200], $config['actions']);
        self::assertSame(['keys' => [], 'max' => 5, 'values_limit' => 10], $config['filters']);
        self::assertSame(['fuzzy' => false], $config['matching']);
        self::assertSame(['enabled' => true, 'builtin_dictionary' => false, 'locales' => Configuration::LOCALES, 'max_terms' => 24], $config['synonyms']);
        self::assertFalse($config['results_page']['enabled']);
        self::assertFalse($config['recent']['revalidate']);
        self::assertSame([], $config['menu_pages']['keywords']);
        self::assertFalse($config['menu_pages']['check_access']);
        self::assertNull($config['menu_pages']['ranked']);
    }

    public function testAPageEntryIsNormalised(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['pages' => [
            ['id' => 'settings.profile', 'label' => 'k', 'route' => 'r', 'trail' => 'crumb', 'route_parameters' => ['a' => 1, 'b' => null]],
        ]]]);
        self::assertSame(['crumb'], $config['pages'][0]['trail']);
        self::assertSame('none', $config['pages'][0]['scope']);
        self::assertSame('messages', $config['pages'][0]['translation_domain']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalid(): iterable
    {
        yield 'page id' => [['pages' => [['id' => 'bad id', 'label' => 'k', 'route' => 'r']]]];
        yield 'scope parameter' => [['pages' => [['id' => 'a', 'label' => 'k', 'route' => 'r', 'scope_parameter' => 'id']]]];
        yield 'route parameter' => [['pages' => [['id' => 'a', 'label' => 'k', 'route' => 'r', 'route_parameters' => ['x' => ['nested']]]]]];
        yield 'scope value' => [['pages' => [['id' => 'a', 'label' => 'k', 'route' => 'r', 'scope' => 'maybe']]]];
        yield 'filter key' => [['filters' => ['keys' => ['Bad Key']]]];
        yield 'locale' => [['synonyms' => ['locales' => ['../etc']]]];
        yield 'registry locale type' => [['page_registry' => ['locales' => [true]]]];
        yield 'action prefix letter' => [['actions' => ['prefix' => 'a']]];
        yield 'action prefix long' => [['actions' => ['prefix' => '>>>>']]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalid')]
    public function testInvalidValuesAreRefused(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [$config]);
    }

    public function testTheWiringFollowsTheConfiguration(): void
    {
        $container = $this->load([]);
        self::assertSame([['priority' => 90]], array_values(array_filter($container->getDefinition(PageRegistryProvider::class)->getTag('nowo_search_kit.provider'))));
        self::assertEquals(new Reference(SynonymExpander::class), $container->getDefinition(SearchEngine::class)->getArgument(6));
        self::assertFalse($container->hasDefinition(ConceptGroupSynonymProvider::class));
        self::assertSame(['fuzzy' => false, 'filter_all_rows' => false, 'action_prefix' => '>', 'actions_in_results' => true], $container->getParameter('nowo_search_kit.engine_options'));
        self::assertSame(['action_prefix' => '>', 'filter_keys' => [], 'results' => false, 'revalidate' => false], $container->getParameter('nowo_search_kit.features'));

        $container = $this->load(['synonyms' => ['enabled' => false, 'builtin_dictionary' => true], 'page_registry' => ['priority' => 7], 'recent' => ['revalidate' => true], 'filters' => ['keys' => ['is', 'is']]]);
        self::assertNull($container->getDefinition(SearchEngine::class)->getArgument(6));
        self::assertTrue($container->getDefinition(ConceptGroupSynonymProvider::class)->hasTag(SearchSynonymProviderInterface::SYNONYM_PROVIDER_TAG));
        self::assertSame([['priority' => 7]], $container->getDefinition(PageRegistryProvider::class)->getTag('nowo_search_kit.provider'));
        self::assertTrue($container->getParameter('nowo_search_kit.recent.revalidate'));
        self::assertSame(['is'], $container->getParameter('nowo_search_kit.filters.keys'));

        $container = $this->load(['recent' => ['enabled' => false, 'revalidate' => true]]);
        self::assertFalse($container->getParameter('nowo_search_kit.recent.revalidate'), 'no recents, no revalidation');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.secret', 's');
        (new NowoSearchKitExtension())->load([$config], $container);

        return $container;
    }
}
