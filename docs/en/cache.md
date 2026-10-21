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
| `FileCache` | every worker on the host | one serialized file per entry, atomic writes, expired entries removed on read and by a probabilistic sweep (`sweep()` runs it on demand) |
| `ArrayCache` | the current process only | tests and per-worker memoization |

Keys follow PSR-16: up to 256 characters, none of `{}()/\@:`; a bad key throws
`InvalidKeyException` (a `Psr\SimpleCache\InvalidArgumentException`). TTLs are seconds or a
`DateInterval`; a non-positive TTL deletes. A stored `null` counts as a hit.

## Worker mode

`ArrayCache` is a per-worker cache: with four workers an entry is computed up to four times
and `clear()` reaches one of them. Use APCu or files for anything that must be consistent
across workers, and keep `ArrayCache` for data that is cheap to rebuild.

`RateLimit` and `Metrics` have their own storages with the same APCu/memory split; they do
not go through this cache.
