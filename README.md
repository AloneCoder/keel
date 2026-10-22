# Keel

[Русская версия](README.ru.md)

A small, worker-first PHP micro-framework: PSR-7/11/14/15/17 all the way, attribute-based
routing with scoped discovery, a compiled container and router, and the same code running
under php-fpm, FrankenPHP worker mode and RoadRunner.

Status: 1.4.0, API frozen under semantic versioning. Documentation site: https://alonecoder.github.io/keel/ Start with `composer create-project keel/skeleton my-app`. See [CHANGELOG.md](CHANGELOG.md) and the roadmap below.

- [Container](docs/en/container.md) · [по-русски](docs/ru/container.md)
- [Routing](docs/en/routing.md) · [по-русски](docs/ru/routing.md)
- [HTTP and kernel](docs/en/http.md) · [по-русски](docs/ru/http.md)
- [Runtimes: php-fpm, FrankenPHP, RoadRunner](docs/en/runtime.md) · [по-русски](docs/ru/runtime.md)
- [Application: config, discovery, events, console](docs/en/app.md) · [по-русски](docs/ru/app.md)
- [Testing](docs/en/testing.md) · [по-русски](docs/ru/testing.md)
- [Sessions and signed cookies](docs/en/session.md) · [по-русски](docs/ru/session.md)
- [Authentication and rate limiting](docs/en/auth.md) · [по-русски](docs/ru/auth.md)
- [OpenAPI](docs/en/openapi.md) · [по-русски](docs/ru/openapi.md)
- [Health and metrics](docs/en/observability.md) · [по-русски](docs/ru/observability.md)
- [Database](docs/en/database.md), [Templates](docs/en/views.md), [Cache](docs/en/cache.md), [HTTP client](docs/en/http-client.md) · [по-русски](docs/ru/database.md)
- [File storage](docs/en/storage.md), [Localization](docs/en/i18n.md) · [по-русски](docs/ru/storage.md)
- [API stability](docs/en/api.md) · [по-русски](docs/ru/api.md)

Try the example:

```bash
php -S 127.0.0.1:8080 -t examples/hello/public examples/hello/public/index.php
curl http://127.0.0.1:8080/hello/world
php examples/hello/bin/console keel:routes
```

The console ships `keel:routes`, `keel:compile`, `keel:cache-clear`, `keel:debug:container`, `keel:doctor`,
`keel:preload`, `keel:openapi`, `keel:serve` (built-in server or FrankenPHP worker mode with
`--watch`), `keel:install` (a component's package, provider, middleware and config in one step),
`keel:ai:init` (`AGENTS.md` and `llms.txt`) and the `keel:make:*` generators for controllers,
commands, middleware, listeners, health checks, authenticators and policies; see
[Application](docs/en/app.md).

Requirements: PHP >= 8.4.

```bash
composer install
composer test
composer stan
composer cs
composer infection
```

CI runs the tests on PHP 8.4 and 8.5, PHPStan at level max, php-cs-fixer, and Infection on
the container, router, auth, rate limiting, health and metrics (MSI gate 80%).

## Packages

`keel/framework` is the whole thing. Four components are also published on their own as read-only
splits of this repository and are `replace`d by the framework: [keel/container](https://github.com/AloneCoder/keel-container),
[keel/router](https://github.com/AloneCoder/keel-router), [keel/http](https://github.com/AloneCoder/keel-http)
(depends on router and validation) and [keel/validation](https://github.com/AloneCoder/keel-validation).
Issues and pull requests go here; `bin/split vX.Y.Z` pushes the splits.

## Benchmarks

In-process, same route (`GET /json/42`, JSON body), production mode with warm caches,
PHP 8.4.21 with opcache, JIT off, Apple Silicon laptop, 30 000 iterations after warm-up.
The number measures framework overhead per request; the SAPI and the network are excluded.
Reproduce with `cd benchmarks && composer install && php -d opcache.enable_cli=1 run.php`.

| Framework | boot (ms) | µs / request | requests / s | memory after boot |
|---|---|---|---|---|
| Keel 0.1.0 (compiled container, cached routes) | 11.8 | 4.8 | 208 000 | 0.21 MB |
| Slim 4.15 (closure routes, no container) | 5.9 | 4.8 | 206 000 | 0.07 MB |
| Symfony 8.1 MicroKernel (FrameworkBundle, `#[Route]`) | 29.5 | 31.0 | 32 000 | 0.52 MB |

Memory: a production boot loads 30 files (26 classes, 3 cache files, 1 config file), about
0.3 MB of heap without opcache and well under 100 KB with it; `Route` objects are materialized
only for routes that match, and nothing survives a request: 2000 requests in a row add 0 bytes.
`keel:preload` generates an `opcache.preload` script so php-fpm links every class once: with
it a boot allocates 4 KB and loads 3 files, and boot plus first request drops from 12.8 ms to 2.7 ms.
Measure with `php -d opcache.enable_cli=1 memory.php` and `memory-classes.php` in `benchmarks/`.

Under a real server: FrankenPHP 1.12 worker mode (8 workers), `oha` with 64 connections for 10 s on
the same laptop, same JSON route: Keel 8 486 requests/s, Slim 8 603 requests/s, median latency 5.2 ms
and p99 34 ms for both, every response 200. Reproduce with `benchmarks/frankenphp/run.sh`.

Keel does more work per request than the Slim app in this table (attribute controller,
argument resolution with type casting, Problem Details handler, container-resolved
middleware) at the same cost. Symfony's number includes its full HttpKernel event cycle
and `terminate()`.

## Roadmap

1.0 closed the core; 1.1 added what a production API needs: authentication (`#[Auth]`, bearer,
API key, HMAC), `#[RateLimit]`, health endpoints, Prometheus metrics, an OpenAPI 3.1 document
derived from the code, and `keel:serve` for FrankenPHP worker mode in development. 1.2 wired the
usual neighbours: PDO or Doctrine DBAL with health checks, Twig or Latte, a dependency-free PSR-16
cache and a PSR-18 client on curl; 1.2.1 added the health-check and authenticator generators and
widened mutation testing. 1.3 hardened the code after a full review, added `keel:doctor`, and
closed the gaps against Slim and Lumen: optional path segments, redirects, route groups, HTTP
caching, CSRF, policies, middleware with arguments, the `Input` reader, log channels, Redis and
Memcached, file storage and localization. 1.4 added what other ecosystems ship first-party:
security headers, body limits, trusted hosts, idempotency keys, Server-Timing, deprecation
headers, streaming and Server-Sent Events, load shedding, lifecycle events, work after the
response, a JWT/JWKS authenticator, an output cache with tags, `keel:install` and
`keel:ai:init`. Next: feedback from real projects decides; queues and schedulers stay out of
scope.

License: MIT.
