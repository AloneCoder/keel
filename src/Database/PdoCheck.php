<?php

declare(strict_types=1);

namespace Keel\Database;

use Keel\Health\HealthCheck;
use Keel\Health\HealthResult;

/**
 * Readiness: a round trip on the PDO connection, with its latency in milliseconds. Reports
 * degraded when a transaction was left open by a previous request (worker mode).
 *
 * Pass a closure returning the connection to connect lazily inside check(): a database that is
 * unreachable then shows up as "down" in the report instead of failing the middleware that
 * builds the check. PdoProvider registers it that way.
 */
final class PdoCheck implements HealthCheck
{
    /** @var \PDO|\Closure(): \PDO */
    private \PDO|\Closure $pdo;

    /**
     * @param \PDO|\Closure(): \PDO $pdo
     */
    public function __construct(\PDO|\Closure $pdo, private readonly string $name = 'database')
    {
        $this->pdo = $pdo;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function check(): HealthResult
    {
        $started = hrtime(true);
        try {
            $pdo = $this->pdo instanceof \Closure ? ($this->pdo)() : $this->pdo;
            $statement = $pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            return HealthResult::down($e->getMessage(), exception: $e);
        }
        if ($statement === false) {
            return HealthResult::down('SELECT 1 failed: ' . implode(' ', array_filter($pdo->errorInfo(), is_scalar(...))));
        }
        $this->pdo = $pdo;
        $ms = round((hrtime(true) - $started) / 1e6, 2);

        return $pdo->inTransaction()
            ? HealthResult::degraded('transaction left open', ['ms' => $ms])
            : HealthResult::up(data: ['ms' => $ms]);
    }
}
