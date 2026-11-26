<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\EntitySearch;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;
use Nowo\SearchKitBundle\Search\Query\FilterCondition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 1.13.0: the typed-filter conditions bind the value, keep NULL columns when negated and refuse unsafe names.
 */
final class FilterConditionTest extends TestCase
{
    public function testEqualsBindsTheValueAndNegationKeepsNullColumns(): void
    {
        $queryBuilder = $this->queryBuilder();
        FilterCondition::equals($queryBuilder, 't.owner', "x' OR '1'='1", false, 'owner0');
        FilterCondition::equals($queryBuilder, 'status', 'open', true, 'status1');
        FilterCondition::equals($queryBuilder, 't.done', true, false, 'done2', ParameterType::BOOLEAN);
        FilterCondition::matchNothing($queryBuilder);

        self::assertSame('SELECT t.id FROM t WHERE (t.owner = :owner0) AND ((status IS NULL OR status <> :status1)) AND (t.done = :done2) AND (1 = 0)', $queryBuilder->getSQL());
        self::assertSame(['owner0' => "x' OR '1'='1", 'status1' => 'open', 'done2' => true], $queryBuilder->getParameters());
        self::assertSame(ParameterType::BOOLEAN, $queryBuilder->getParameterTypes()['done2']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsafe(): iterable
    {
        yield 'field' => ['t.owner = 1 OR 1', 'p'];
        yield 'nested field' => ['a.b.c', 'p'];
        yield 'parameter' => ['t.owner', 'p; --'];
        yield 'parameter starting with a digit' => ['t.owner', '1p'];
        yield 'reserved parameter' => ['t.owner', 'nowo_search_w0'];
    }

    #[DataProvider('unsafe')]
    public function testUnsafeNamesAreRefused(string $field, string $parameter): void
    {
        $this->expectException(InvalidArgumentException::class);

        FilterCondition::equals($this->queryBuilder(), $field, 'x', false, $parameter);
    }

    private function queryBuilder(): QueryBuilder
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])->createQueryBuilder()->select('t.id')->from('t');
    }
}
