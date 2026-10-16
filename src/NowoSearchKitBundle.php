<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle;

use Nowo\SearchKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\SearchKitBundle\DependencyInjection\NowoSearchKitExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Global header search for Symfony: a magnifier that stretches into a field, grouped permission-aware results from
 * the application's SearchProviderInterface services; see docs/USAGE.md.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoSearchKitBundle extends Bundle
{
    /**
     * Registers the Twig template paths compiler pass.
     *
     * @param ContainerBuilder $container The container being compiled
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new TwigPathsPass());
    }

    /**
     * Returns the bundle extension (alias `nowo_search_kit`).
     *
     * @return ExtensionInterface The lazily created NowoSearchKitExtension
     */
    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            // @igor-ignore - Symfony bundle extension lazy-init at boot; not request state
            $this->extension = new NowoSearchKitExtension();
        }

        return $this->extension;
    }
}
