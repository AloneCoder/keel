<?php

declare(strict_types=1);

namespace Keel\Database;

use Keel\Container\ContainerInterface;
use Keel\Container\Definitions;
use Keel\Container\ServiceProvider;
use Keel\Health\HealthCheck;

/**
 * Registers \PDO built from PdoConfig, created on first use and kept for the life of the
 * worker, plus a readiness check tagged keel.health. List it in config/services.php.
 *
 * The check receives the connection lazily, so an unreachable database is reported as down by
 * /health rather than breaking the request pipeline while the check is built.
 */
final class PdoProvider implements ServiceProvider
{
    public function register(Definitions $definitions): void
    {
        $definitions->set(\PDO::class)->factory([self::class, 'connect']);
        $definitions->set(PdoCheck::class)->factory([self::class, 'check'])->tag(HealthCheck::TAG);
    }

    public static function connect(PdoConfig $config): \PDO
    {
        return new \PDO($config->dsn, $config->username, $config->password, $config->options());
    }

    public static function check(ContainerInterface $container): PdoCheck
    {
        return new PdoCheck(static fn(): \PDO => $container->get(\PDO::class));
    }
}
