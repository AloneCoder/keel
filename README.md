# Keel

[Русская версия](README.ru.md)

A small, worker-first PHP micro-framework: PSR-7/11/14/15/17 all the way, attribute-based
routing with scoped discovery, a compiled container and router, and the same code running
under php-fpm, FrankenPHP worker mode and RoadRunner.

Status: M4 done (container, routing, HTTP kernel, runtimes, app bootstrap with config, discovery, events and console). See the roadmap in the design document.

- [Landscape analysis](docs/en/analysis.md) · [по-русски](docs/ru/analysis.md)
- [Design decisions and roadmap](docs/en/design.md) · [по-русски](docs/ru/design.md)
- [Container](docs/en/container.md) · [по-русски](docs/ru/container.md)
- [Routing](docs/en/routing.md) · [по-русски](docs/ru/routing.md)
- [HTTP and kernel](docs/en/http.md) · [по-русски](docs/ru/http.md)
- [Runtimes: php-fpm, FrankenPHP, RoadRunner](docs/en/runtime.md) · [по-русски](docs/ru/runtime.md)
- [Application: config, discovery, events, console](docs/en/app.md) · [по-русски](docs/ru/app.md)

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

License: MIT.
