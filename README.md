# Keel

[Русская версия](README.ru.md)

A small, worker-first PHP micro-framework: PSR-7/11/14/15/17 all the way, attribute-based
routing with scoped discovery, a compiled container and router, and the same code running
under php-fpm, FrankenPHP worker mode and RoadRunner.

Status: 0.2.0, ready for a first real project. Start with `composer create-project keel/skeleton my-app`. See [CHANGELOG.md](CHANGELOG.md). See the roadmap in the design document.

- [Landscape analysis](docs/en/analysis.md) · [по-русски](docs/ru/analysis.md)
- [Design decisions and roadmap](docs/en/design.md) · [по-русски](docs/ru/design.md)
- [Container](docs/en/container.md) · [по-русски](docs/ru/container.md)
- [Routing](docs/en/routing.md) · [по-русски](docs/ru/routing.md)
- [HTTP and kernel](docs/en/http.md) · [по-русски](docs/ru/http.md)
- [Runtimes: php-fpm, FrankenPHP, RoadRunner](docs/en/runtime.md) · [по-русски](docs/ru/runtime.md)
- [Application: config, discovery, events, console](docs/en/app.md) · [по-русски](docs/ru/app.md)
- [Testing](docs/en/testing.md) · [по-русски](docs/ru/testing.md)

Try the example:

```bash
php -S 127.0.0.1:8080 -t examples/hello/public examples/hello/public/index.php
curl http://127.0.0.1:8080/hello/world
php examples/hello/bin/console keel:routes
```

Requirements: PHP >= 8.4.

```bash
composer install
composer test
composer stan
composer cs
```

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

Keel does more work per request than the Slim app in this table (attribute controller,
argument resolution with type casting, Problem Details handler, container-resolved
middleware) at the same cost. Symfony's number includes its full HttpKernel event cycle
and `terminate()`.

## Roadmap

M0–M5 of the [design document](docs/en/design.md) are done. Next: per-component packages
(`keel/container`, `keel/router`), an HTML error page for debug mode, a Fibers-based runtime
experiment, and real-world adoption feedback before 1.0.

License: MIT.
