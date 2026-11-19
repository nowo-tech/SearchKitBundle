<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\EntitySearch;

use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Test\Internal\RenderRecorder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Audit VK-2: the kit's RenderRecorder forwards every hook, so a hook added to EntitySearchDefinition later cannot
 * silently fall back to its default while the kit wraps a real definition.
 */
final class RenderRecorderTest extends TestCase
{
    public function testEveryPublicHookOfTheDefinitionIsDeclaredByTheRecorder(): void
    {
        $missing = [];
        foreach ((new ReflectionClass(EntitySearchDefinition::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }
            if ((new ReflectionMethod(RenderRecorder::class, $method->getName()))->getDeclaringClass()->getName() !== RenderRecorder::class) {
                $missing[] = $method->getName();
            }
        }

        self::assertSame([], $missing, 'RenderRecorder must declare (forward) every public method of EntitySearchDefinition.');
    }
}
