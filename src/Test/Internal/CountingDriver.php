<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Test\Internal;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Nowo\SearchKitBundle\Test\QueryCounter;

/**
 * @internal Driver of QueryCountingMiddleware
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class CountingDriver extends AbstractDriverMiddleware
{
    /**
     * @param Driver $driver The wrapped driver
     * @param QueryCounter $counter Receives every statement
     */
    public function __construct(Driver $driver, private readonly QueryCounter $counter)
    {
        parent::__construct($driver);
    }

    /**
     * Connects and wraps the connection.
     */
    public function connect(array $params): Connection
    {
        return new CountingConnection(parent::connect($params), $this->counter);
    }
}
