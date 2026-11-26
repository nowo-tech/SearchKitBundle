<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\EntitySearch;

use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use Nowo\SearchKitBundle\Model\SearchFilter;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Test\Internal\RenderRecorder;
use Nowo\SearchKitBundle\Test\QueryCounter;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\NoteDatabase;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\StubDefinition;
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

    /**
     * 1.13.0: the filter hooks reach the wrapped definition.
     */
    public function testFilterHooksAreForwarded(): void
    {
        $inner             = new StubDefinition(NoteDatabase::get());
        $inner->filterKeys = ['is'];
        $seen              = [];
        $inner->filters    = static function (DbalQueryBuilder|OrmQueryBuilder $queryBuilder, array $filters) use (&$seen): bool {
            $seen = $filters;

            return true;
        };
        $recorder = new RenderRecorder($inner, new QueryCounter());
        $filter   = new SearchFilter('is', 'open');
        $query    = SearchQuery::forUserIdentifier('alice', 'x', 10);

        self::assertSame(['is'], $recorder->supportedFilters());
        self::assertTrue($recorder->applyFilters($inner->baseQuery('tenant-a', $query), [$filter], 'tenant-a', $query));
        self::assertSame([$filter], $seen);
    }
}
