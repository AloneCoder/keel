<?php

declare(strict_types=1);

namespace Keel\Database;

/**
 * Connection parameters for Doctrine DBAL (composer require doctrine/dbal). Register one in
 * config/database.php and load DbalProvider. Build it from a URL with DbalConfig::fromUrl().
 *
 * @phpstan-import-type Params from \Doctrine\DBAL\DriverManager
 */
final readonly class DbalConfig
{
    /**
     * @param Params $params as accepted by Doctrine\DBAL\DriverManager::getConnection()
     */
    public function __construct(#[\SensitiveParameter] public array $params) {}

    /**
     * "mysql://user:pass@host:3306/db?charset=utf8mb4", "pgsql://...", "sqlite:///path/to/file.db",
     * "sqlite:///:memory:" through Doctrine's DsnParser with the usual scheme names.
     */
    public static function fromUrl(#[\SensitiveParameter] string $url): self
    {
        if (!class_exists(\Doctrine\DBAL\Tools\DsnParser::class)) {
            throw new \RuntimeException('DbalConfig::fromUrl() needs doctrine/dbal: composer require doctrine/dbal');
        }

        $parser = new \Doctrine\DBAL\Tools\DsnParser([
            'mysql' => 'pdo_mysql',
            'mysql2' => 'pdo_mysql',
            'mariadb' => 'pdo_mysql',
            'postgres' => 'pdo_pgsql',
            'postgresql' => 'pdo_pgsql',
            'pgsql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            'sqlite3' => 'pdo_sqlite',
            'mssql' => 'pdo_sqlsrv',
            'sqlsrv' => 'pdo_sqlsrv',
            'oci' => 'pdo_oci',
        ]);

        return new self($parser->parse($url));
    }
}
