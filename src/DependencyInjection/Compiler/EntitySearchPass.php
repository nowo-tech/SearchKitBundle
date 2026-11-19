<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\DependencyInjection\Compiler;

use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Contract\SearchReferenceResolverInterface;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchProvider;
use Nowo\SearchKitBundle\Search\Group\SearchGroupSettings;
use Nowo\SearchKitBundle\Search\Group\SensitiveGroupNotice;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

use function is_numeric;

/**
 * 1.9.0: registers one EntitySearchProvider per EntitySearchDefinition service (tag EntitySearchDefinition::TAG) — the
 * provider tag carries the definition tag's `priority` (default 0), and the provider also resolves the group's
 * recents. The provider service id is `nowo_search_kit.entity_provider.<definition id>`.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class EntitySearchPass implements CompilerPassInterface
{
    /** Prefix of the generated provider service ids. */
    public const string PROVIDER_PREFIX = 'nowo_search_kit.entity_provider.';

    /**
     * @param ContainerBuilder $container The container being compiled
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(SearchGroupSettings::class)) {
            return;
        }
        foreach ($container->findTaggedServiceIds(EntitySearchDefinition::TAG) as $id => $tags) {
            $priority = $tags[0]['priority'] ?? 0;
            $priority = is_numeric($priority) ? (int) $priority : 0;
            $container->register(self::PROVIDER_PREFIX . $id, EntitySearchProvider::class)
                ->setArguments([
                    new Reference($id),
                    new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    new Reference(SearchGroupSettings::class),
                    '%nowo_search_kit.recent.safe%',
                    new Reference(SensitiveGroupNotice::class),
                ])
                ->addTag(SearchProviderInterface::TAG, ['priority' => $priority])
                ->addTag(SearchReferenceResolverInterface::REFERENCE_RESOLVER_TAG, ['priority' => $priority]);
        }
    }
}
