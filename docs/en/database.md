# Database

Namespace `Keel\Database`. Keel has no ORM and no query builder: it wires the connection you
already know into the container, keeps it for the life of the worker and watches it from
`/health`. Pick plain PDO or Doctrine DBAL.

## PDO

```php
// config/database.php
return static fn(Env $env): PdoConfig => new PdoConfig(
    $env->get('DATABASE_DSN') ?? 'mysql:host=127.0.0.1;dbname=app;charset=utf8mb4',
    $env->get('DATABASE_USER'),
    $env->get('DATABASE_PASSWORD'),
);

// config/services.php
return new ServicesConfig([PdoProvider::class]);
```

`PdoProvider` registers `\PDO` as a shared service created on first use, with exceptions
(`ERRMODE_EXCEPTION`), associative fetches, native prepares and no stringified fetches unless
`PdoConfig::$options` says otherwise. It also registers `PdoCheck`, tagged `keel.health`, which
runs `SELECT 1`, reports the latency and flags a transaction left open as degraded. The check
gets the connection lazily (a closure resolving `\PDO` from the container), so a database that
cannot be reached is a `down` entry in `/health` instead of an exception while the middleware is
built; the connection is retried on the next probe and kept once it succeeds. Build your own
checks the same way: `new PdoCheck(fn() => $container->get(\PDO::class), 'replica')`. The error
message is logged, not shown, unless `HealthConfig::$exposeDetails` is on
(see [Health and metrics](observability.md#health-checks)).

```php
final class UserRepository
{
    public function __construct(private readonly \PDO $pdo) {}
}
```

## Doctrine DBAL

```bash
composer require doctrine/dbal
```

```php
// config/database.php
return static fn(Env $env): DbalConfig => DbalConfig::fromUrl($env->get('DATABASE_URL') ?? 'sqlite:///:memory:');

// config/services.php
return new ServicesConfig([DbalProvider::class]);
```

`DbalConfig::fromUrl()` understands `mysql://`, `mariadb://`, `pgsql://`, `sqlite:///path`,
`sqlite:///:memory:`, `sqlsrv://` and `oci://`; `new DbalConfig([...])` takes the parameter array
of `DriverManager::getConnection()` as is. `DbalProvider` registers `Doctrine\DBAL\Connection`
(DBAL connects lazily on the first query) and `DbalCheck`, tagged `keel.health`, which runs the
platform's dummy select, reports the latency and flags a transaction left open as degraded.

## Worker mode

The connection outlives the request. Two rules follow:

- **Never leave a transaction open.** Wrap transactional work in `try/finally` or
  `Connection::transactional()`; `DbalCheck` reports an open transaction as degraded, because
  the next request on that worker would run inside it.
- **Expect the server to drop idle connections.** DBAL reconnects after `close()`; with PDO,
  catch the "server has gone away" exception in a retry of your own, or lower the worker's
  `MAX_REQUESTS` below the server's idle timeout divided by your request rate. The shared `\PDO`
  is not recreated by the framework: once the server has dropped it, `PdoCheck` reports the
  worker as down until it is restarted, which is what a readiness probe should do.

Per-request state (identity maps, caches of the current user) belongs in `Resettable` services,
not on the connection.

## Migrations and ORMs

Doctrine Migrations, Phinx and Doctrine ORM work on top of the DBAL connection: register
their services in a provider that takes `Connection` from the container. An ORM's entity
manager is per-request state in worker mode; make the service `Resettable` and call
`clear()` in `reset()`.
