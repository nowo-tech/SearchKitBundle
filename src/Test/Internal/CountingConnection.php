<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Test\Internal;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Nowo\SearchKitBundle\Test\QueryCounter;

/**
 * @internal Connection of QueryCountingMiddleware: records prepare(), query() and exec()
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class CountingConnection extends AbstractConnectionMiddleware
{
    /**
     * @param Connection $connection The wrapped connection
     * @param QueryCounter $counter Receives every statement
     */
    public function __construct(Connection $connection, private readonly QueryCounter $counter)
    {
        parent::__construct($connection);
    }

    /**
     * Records and prepares a statement.
     */
    public function prepare(string $sql): Statement
    {
        $this->counter->record($sql);

        return parent::prepare($sql);
    }

    /**
     * Records and runs a query.
     */
    public function query(string $sql): Result
    {
        $this->counter->record($sql);

        return parent::query($sql);
    }

    /**
     * Records and runs a statement.
     *
     * Declared `int`, which both DBAL 3 (`exec(): int`) and DBAL 4 (`exec(): int|string`) accept (1.11.0; 1.9–1.10
     * declared the DBAL 4 type, a fatal error on DBAL 3). DBAL 4 gives a numeric string only for a row count above
     * PHP_INT_MAX, which a test never reaches.
     */
    public function exec(string $sql): int
    {
        $this->counter->record($sql);

        return (int) parent::exec($sql);
    }
}
