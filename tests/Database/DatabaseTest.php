<?php

declare(strict_types=1);

namespace Keel\Tests\Database;

use Doctrine\DBAL\Connection;
use Keel\Container\Container;
use Keel\Container\Definitions;
use Keel\Database\DbalCheck;
use Keel\Database\DbalConfig;
use Keel\Database\DbalProvider;
use Keel\Database\PdoCheck;
use Keel\Database\PdoConfig;
use Keel\Database\PdoProvider;
use Keel\Health\HealthCheck;
use Keel\Health\HealthStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[CoversClass(PdoConfig::class)]
#[CoversClass(PdoProvider::class)]
#[CoversClass(PdoCheck::class)]
#[CoversClass(DbalConfig::class)]
#[CoversClass(DbalProvider::class)]
#[CoversClass(DbalCheck::class)]
#[RequiresPhpExtension('pdo_sqlite')]
final class DatabaseTest extends TestCase
{
    public function testPdoProviderBuildsASharedConnectionWithSaneDefaults(): void
    {
        $definitions = new Definitions();
        $definitions->instance(PdoConfig::class, new PdoConfig('sqlite::memory:', options: [\PDO::ATTR_TIMEOUT => 3]));
        $definitions->load(new PdoProvider());
        $container = new Container($definitions);

        $pdo = $container->get(\PDO::class);
        self::assertSame($pdo, $container->get(\PDO::class));
        self::assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
        self::assertSame(\PDO::FETCH_ASSOC, $pdo->getAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE));
        $statement = $pdo->query('SELECT 1 AS n');
        self::assertNotFalse($statement);
        self::assertSame(['n' => 1], $statement->fetch());

        $checks = $container->tagged(HealthCheck::TAG);
        self::assertCount(1, $checks);
        self::assertInstanceOf(PdoCheck::class, $checks[0]);
        $result = $checks[0]->check();
        self::assertSame(HealthStatus::Up, $result->status);
        self::assertArrayHasKey('ms', $result->data);
        self::assertSame('database', $checks[0]->name());
    }

    public function testPdoCheckReportsDown(): void
    {
        $pdo = new class extends \PDO {
            public function __construct()
            {
                parent::__construct('sqlite::memory:');
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                throw new \PDOException('gone away');
            }
        };

        $result = new PdoCheck($pdo, 'primary')->check();
        self::assertSame(HealthStatus::Down, $result->status);
        self::assertSame('gone away', $result->detail);
        self::assertInstanceOf(\PDOException::class, $result->exception, 'the exception travels with the result for logging');

        $silent = new class extends \PDO {
            public function __construct()
            {
                parent::__construct('sqlite::memory:');
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                return false;
            }
        };
        self::assertSame(HealthStatus::Down, new PdoCheck($silent)->check()->status, 'a false return (ERRMODE_SILENT) is down as well');
    }

    public function testPdoCheckConnectsLazilyAndFlagsOpenTransactions(): void
    {
        $attempts = 0;
        $check = new PdoCheck(static function () use (&$attempts): \PDO {
            if (++$attempts === 1) {
                throw new \PDOException('SQLSTATE[HY000] [2002] Connection refused');
            }

            return new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        });

        $down = $check->check();
        self::assertSame(HealthStatus::Down, $down->status, 'an unreachable database is a down check, not a broken middleware');
        self::assertSame('SQLSTATE[HY000] [2002] Connection refused', $down->detail);

        self::assertSame(HealthStatus::Up, $check->check()->status, 'the connection is retried on the next probe');
        self::assertSame(2, $attempts);
        self::assertSame(HealthStatus::Up, $check->check()->status);
        self::assertSame(2, $attempts, 'once connected, the connection is kept');

        $pdo = new \PDO('sqlite::memory:');
        $pdo->beginTransaction();
        $open = new PdoCheck($pdo)->check();
        self::assertSame(HealthStatus::Degraded, $open->status);
        self::assertSame('transaction left open', $open->detail);
        $pdo->rollBack();
        self::assertSame(HealthStatus::Up, new PdoCheck($pdo)->check()->status);

        // Through the provider: the check is built even though the database cannot be opened.
        $definitions = new Definitions();
        $definitions->instance(PdoConfig::class, new PdoConfig('sqlite:/nonexistent/dir/db.sqlite'));
        $definitions->load(new PdoProvider());
        $checks = new Container($definitions)->tagged(HealthCheck::TAG);
        self::assertCount(1, $checks);
        self::assertInstanceOf(PdoCheck::class, $checks[0]);
        self::assertSame(HealthStatus::Down, $checks[0]->check()->status);
    }

    public function testDbalProviderAndUrlParsing(): void
    {
        $config = DbalConfig::fromUrl('sqlite:///:memory:');
        self::assertSame('pdo_sqlite', $config->params['driver'] ?? null);

        $definitions = new Definitions();
        $definitions->instance(DbalConfig::class, $config);
        $definitions->load(new DbalProvider());
        $container = new Container($definitions);

        $connection = $container->get(Connection::class);
        self::assertSame(1, $connection->fetchOne('SELECT 1'));
        self::assertSame($connection, $container->get(Connection::class));

        $check = $container->tagged(HealthCheck::TAG)[0];
        self::assertInstanceOf(DbalCheck::class, $check);
        self::assertSame(HealthStatus::Up, $check->check()->status);

        $connection->beginTransaction();
        $degraded = $check->check();
        self::assertSame(HealthStatus::Degraded, $degraded->status);
        self::assertSame('transaction left open', $degraded->detail);
        $connection->rollBack();

        $connection->close();
        self::assertSame(HealthStatus::Up, $check->check()->status, 'DBAL reconnects after close()');
    }

    public function testDbalCheckReportsDownOnABrokenConnection(): void
    {
        $definitions = new Definitions();
        $definitions->instance(DbalConfig::class, new DbalConfig(['driver' => 'pdo_sqlite', 'path' => '/nonexistent/dir/db.sqlite']));
        $definitions->load(new DbalProvider());
        $check = new Container($definitions)->tagged(HealthCheck::TAG)[0];
        self::assertInstanceOf(DbalCheck::class, $check);

        self::assertSame(HealthStatus::Down, $check->check()->status);
    }
}
