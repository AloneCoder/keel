<?php

declare(strict_types=1);

namespace Keel\Database;

use Keel\Health\HealthCheck;
use Keel\Health\HealthResult;

/**
 * Readiness: a round trip on the PDO connection, with its latency in milliseconds.
 */
final class PdoCheck implements HealthCheck
{
    public function __construct(private readonly \PDO $pdo, private readonly string $name = 'database') {}

    public function name(): string
    {
        return $this->name;
    }

    public function check(): HealthResult
    {
        $started = hrtime(true);
        try {
            $this->pdo->query('SELECT 1');
        } catch (\PDOException $e) {
            return HealthResult::down($e->getMessage());
        }

        return HealthResult::up(data: ['ms' => round((hrtime(true) - $started) / 1e6, 2)]);
    }
}
