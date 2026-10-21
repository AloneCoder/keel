<?php

declare(strict_types=1);

namespace Keel\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Keel\Container\Definitions;
use Keel\Container\ServiceProvider;
use Keel\Health\HealthCheck;

/**
 * Registers Doctrine\DBAL\Connection built from DbalConfig, connected lazily on first query
 * (DBAL's own laziness) and kept for the life of the worker, plus a readiness check tagged
 * keel.health. Needs composer require doctrine/dbal.
 */
final class DbalProvider implements ServiceProvider
{
    public function register(Definitions $definitions): void
    {
        if (!class_exists(Connection::class)) {
            throw new \RuntimeException('DbalProvider needs doctrine/dbal: composer require doctrine/dbal');
        }

        $definitions->set(Connection::class)->factory([self::class, 'connect']);
        $definitions->set(DbalCheck::class)->tag(HealthCheck::TAG);
    }

    public static function connect(DbalConfig $config): Connection
    {
        return DriverManager::getConnection($config->params);
    }
}
