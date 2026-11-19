<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Test;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Nowo\SearchKitBundle\Test\Internal\CountingDriver;

/**
 * DBAL middleware of the query-budget assertion (1.9.0 test kit): every statement prepared, queried or executed on the
 * connection is recorded by the QueryCounter. Add it to the test connection's configuration
 * (`$configuration->setMiddlewares([new QueryCountingMiddleware($counter)])`), also the one of a test EntityManager.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class QueryCountingMiddleware implements Middleware
{
    /**
     * @param QueryCounter $counter Receives every statement
     */
    public function __construct(
        private QueryCounter $counter,
    ) {
    }

    /**
     * Wraps the driver so that every statement is counted.
     */
    public function wrap(Driver $driver): Driver
    {
        return new CountingDriver($driver, $this->counter);
    }
}
