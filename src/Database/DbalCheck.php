<?php

declare(strict_types=1);

namespace Keel\Database;

use Doctrine\DBAL\Connection;
use Keel\Health\HealthCheck;
use Keel\Health\HealthResult;

/**
 * Readiness: a round trip on the DBAL connection, with its latency in milliseconds.
 * Reports degraded when a transaction was left open by a previous request (worker mode).
 */
final class DbalCheck implements HealthCheck
{
    public function __construct(private readonly Connection $connection, private readonly string $name = 'database') {}

    public function name(): string
    {
        return $this->name;
    }

    public function check(): HealthResult
    {
        $started = hrtime(true);
        try {
            $this->connection->executeQuery($this->connection->getDatabasePlatform()->getDummySelectSQL())->free();
        } catch (\Throwable $e) {
            return HealthResult::down($e->getMessage(), exception: $e);
        }
        $ms = round((hrtime(true) - $started) / 1e6, 2);

        return $this->connection->isTransactionActive()
            ? HealthResult::degraded('transaction left open', ['ms' => $ms])
            : HealthResult::up(data: ['ms' => $ms]);
    }
}
