# Cache

Namespace `Keel\Cache`. A dependency-free PSR-16 cache with three drivers, wired as
`Psr\SimpleCache\CacheInterface`; swap in symfony/cache or any PSR-16 implementation by aliasing
the interface yourself.

```php
// config/cache.php
return static fn(Env $env, string $projectDir): CacheConfig => new CacheConfig(
    driver: 'auto',                           // apcu when loaded, else file; or "apcu", "file", "array"
    directory: $projectDir . '/var/cache/data',
    prefix: 'myapp:',
    defaultTtl: 3600,                         // seconds for set() without a TTL; null = forever
);

// config/services.php
return new ServicesConfig([CacheProvider::class]);
```

```php
final class RatesService
{
    public function __construct(private readonly CacheInterface $cache, private readonly ClientInterface $http) {}

    public function rate(string $currency): float
    {
        $rate = $this->cache->get('rate.' . $currency);
        if (!is_float($rate)) {
            $rate = $this->fetch($currency);
            $this->cache->set('rate.' . $currency, $rate, 600);
        }

        return $rate;
    }
}
```

## Drivers

| Driver | Shared by | Notes |
|---|---|---|
| `ApcuCache` | every php-fpm child or FrankenPHP thread of the server | fastest; cleared on restart; needs ext-apcu with `apc.enable_cli=1` for console commands |
| `FileCache` | every worker on the host | one serialized file per entry, atomic writes, expired entries removed on read and by a probabilistic sweep (`sweep()` runs it on demand, and drops temporary files a crashed write left behind) |
| `ArrayCache` | the current process only | tests and per-worker memoization |
| `RedisCache` (`driver: 'redis'`) | every worker on every host | ext-redis through `RedisProvider` (below); values serialized with `serialize()`, so keep the instance private |
| `MemcachedCache` (`driver: 'memcached'`) | every worker on every host | ext-memcached, `servers: ['host:11211']`; keys over 250 bytes are hashed; `clear()` flushes the whole instance |

Keys follow PSR-16: up to 256 characters, none of `{}()/\@:`; a bad key throws
`InvalidKeyException` (a `Psr\SimpleCache\InvalidArgumentException`). TTLs are seconds or a
`DateInterval`; a non-positive TTL deletes. A stored `null` counts as a hit.

**`FileCache` directory.** Entries are unserialized with every class allowed, so whoever can
write to the directory can inject objects into your process. The driver creates a missing
directory with mode `0700` and refuses one that is a symlink, writable by everyone, or
group-writable while owned by another user (`RuntimeException`). Without `directory` the default
is a predictable path under the system temp dir: fine on a host that runs only your
application, but on a shared machine set `directory` to a private path such as
`$projectDir . '/var/cache/data'`.

`Keel\Cache\Cache` (an alias of the PSR interface) adds `remember($key, $ttl, fn() => ...)`:
the cached value, or the closure's result stored under the key. A stored `null` is a hit.

## Redis

`RedisConfig` in `config/redis.php` plus `RedisProvider` give the container a `\Redis`
connection (ext-redis, connected on first use, persistent across the requests of a worker), a
`redis` health check for `/health`, and, per `use`, the Keel storages that otherwise live in
APCu or process memory:

```php
// config/redis.php
return static fn(Env $env): RedisConfig => RedisConfig::fromUrl(
    $env->require('REDIS_URL'),               // redis://:password@host:6379/2, rediss:// for TLS
    prefix: 'myapp:',
    use: ['sessions', 'counters', 'metrics'],
);
// or new RedisConfig(host: '127.0.0.1', port: 6379, auth: null, database: 0, prefix: 'myapp:', use: [...])

// config/cache.php
return new CacheConfig(driver: 'redis', prefix: 'myapp:');
```

| `use` | Registers | Replaces |
|---|---|---|
| `sessions` | `Keel\Session\RedisStorage` as `SessionStorageInterface` | file or cookie sessions: shared by every node, expired by Redis, refreshed like file sessions |
| `counters` | `Keel\RateLimit\RedisCounters` as `CounterStorage` | APCu rate-limit counters: one bucket per fleet; fails closed when Redis is unreachable |
| `metrics` | `Keel\Metrics\RedisStorage` as `MetricsStorage` | APCu metrics: one hash for the fleet, so `/metrics` on any node reports all of it |

Every key starts with the prefix (`myapp:cache:`, `myapp:sess:`, `myapp:rl:`, `myapp:metrics`).
`RedisProvider::connect()` builds the connection; register `RedisConfig` before loading the
provider (configs are registered first, so `config/redis.php` is enough).

## Output cache

`#[OutputCache]` serves a route's whole response from the cache: the handler runs once, later
GET and HEAD requests get the stored copy until it expires or one of its tags is invalidated.
It needs a PSR-16 `CacheInterface` (`CacheProvider`).

```php
#[Get('/products/{id}')]
#[OutputCache(ttl: 300, tags: ['products', 'product:{id}'], varyByQuery: ['lang'], varyByHeaders: ['Accept-Language'])]
public function show(int $id): ProductView { /* ... */ }

#[Patch('/products/{id}')]
public function update(int $id, #[FromBody] ProductPatch $patch, OutputCacheStore $pages): ProductView
{
    $product = $this->products->update($id, $patch);
    $pages->invalidateTags('products', "product:$id");

    return ProductView::from($product);
}
```

The key is the method, host and path, plus the query string (`varyByQuery: true`, the default;
`false` ignores it; a list keeps only those parameters), the listed request headers and, with
`varyByPrincipal: true`, the principal. Tags may name route placeholders (`product:{id}`).
`invalidateTags()` gives each tag a new version; a stored response remembers the versions it was
stored with and is stale once one changed, which works on any PSR-16 store without scanning keys.
Tag versions are stored without expiry, and one that was evicted reads as unknown, so its
responses become stale rather than fresh again.

Responses get `X-Cache: HIT` or `MISS` (`OutputCacheConfig::$header`, null for none) and, on a
hit, `Age`. Only 200 responses are stored; never those that set a cookie, say
`Cache-Control: no-store` or `private`, stream their body or exceed
`OutputCacheConfig::$maxBodyBytes` (1 MiB). Requests with an `Authorization` header bypass the
cache unless the route varies by principal and the principal is known by then: declare `#[Auth]`
before `#[OutputCache]`. Without it such requests bypass the cache rather than share one copy.
Unlike `#[CacheControl]`, which tells clients and proxies what to keep, this cache saves the work
on the server.

## Worker mode

`ArrayCache` is a per-worker cache: with four workers an entry is computed up to four times
and `clear()` reaches one of them. Use APCu or files for anything that must be consistent
across workers, and keep `ArrayCache` for data that is cheap to rebuild.

`RateLimit` and `Metrics` have their own storages with the same APCu/memory split; they do
not go through this cache.
