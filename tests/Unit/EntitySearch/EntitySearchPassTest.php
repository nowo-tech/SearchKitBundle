<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\EntitySearch;

use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\DependencyInjection\Compiler\EntitySearchPass;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Search\Group\SearchGroupSettings;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\StubDefinition;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class EntitySearchPassTest extends TestCase
{
    public function testNothingIsRegisteredWithoutSearchKitServices(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.definition', StubDefinition::class)->addTag(EntitySearchDefinition::TAG);

        (new EntitySearchPass())->process($container);

        self::assertFalse($container->hasDefinition(EntitySearchPass::PROVIDER_PREFIX . 'app.definition'));
    }

    public function testTagPriorityIsCarried(): void
    {
        $container = new ContainerBuilder();
        $container->register(SearchGroupSettings::class, SearchGroupSettings::class);
        $container->register('app.definition', StubDefinition::class)->addTag(EntitySearchDefinition::TAG, ['priority' => 30]);

        (new EntitySearchPass())->process($container);

        $provider = $container->getDefinition(EntitySearchPass::PROVIDER_PREFIX . 'app.definition');
        self::assertSame([['priority' => 30]], $provider->getTag(SearchProviderInterface::TAG));
    }
}
