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
     */
    public function exec(string $sql): int|string
    {
        $this->counter->record($sql);

        return parent::exec($sql);
    }
}
