# Keel

[English version](README.md)

Небольшой worker-first микро-фреймворк на PHP: PSR-7/11/14/15/17 целиком, роутинг на
атрибутах с ограниченным discovery, компилируемые контейнер и роутер, и один и тот же код
под php-fpm, FrankenPHP worker mode и RoadRunner.

Статус: стадия проектирования, кода ядра пока нет.

- [Анализ рынка](docs/ru/analysis.md) · [English](docs/en/analysis.md)
- [Проектные решения и дорожная карта](docs/ru/design.md) · [English](docs/en/design.md)

Требования: PHP >= 8.4.

```bash
composer install
composer test
composer stan
composer cs
```

Лицензия: MIT.
