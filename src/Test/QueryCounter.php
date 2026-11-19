<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Test;

use function count;

/**
 * Counts the SQL statements sent through a QueryCountingMiddleware connection (1.9.0 test kit). Test code only: one
 * instance per test, never a service.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class QueryCounter
{
    /** @var list<string> */
    private array $queries = [];

    /**
     * Records one statement.
     *
     * @param string $sql The SQL sent
     */
    public function record(string $sql): void
    {
        $this->queries[] = $sql;
    }

    /**
     * Statements recorded since the last reset.
     */
    public function count(): int
    {
        return count($this->queries);
    }

    /**
     * The statements recorded since the last reset (for a failure message).
     *
     * @return list<string>
     */
    public function queries(): array
    {
        return $this->queries;
    }

    /**
     * Forgets the statements recorded so far.
     */
    public function reset(): void
    {
        $this->queries = [];
    }
}
