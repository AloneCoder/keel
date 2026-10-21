# Keel

[Русская версия](README.ru.md)

A small, worker-first PHP micro-framework: PSR-7/11/14/15/17 all the way, attribute-based
routing with scoped discovery, a compiled container and router, and the same code running
under php-fpm, FrankenPHP worker mode and RoadRunner.

Status: design stage, no runtime code yet.

- [Landscape analysis](docs/en/analysis.md) · [по-русски](docs/ru/analysis.md)
- [Design decisions and roadmap](docs/en/design.md) · [по-русски](docs/ru/design.md)

Requirements: PHP >= 8.4.

```bash
composer install
composer test
composer stan
composer cs
```

License: MIT.
