# Keel

[English version](README.md)

Небольшой worker-first микро-фреймворк на PHP: PSR-7/11/14/15/17 целиком, роутинг на
атрибутах с ограниченным discovery, компилируемые контейнер и роутер, и один и тот же код
под php-fpm, FrankenPHP worker mode и RoadRunner.

Статус: этап M3 (контейнер, роутинг, HTTP-ядро, рантаймы) завершён. Дорожная карта — в документе с решениями.

- [Анализ рынка](docs/ru/analysis.md) · [English](docs/en/analysis.md)
- [Проектные решения и дорожная карта](docs/ru/design.md) · [English](docs/en/design.md)
- [Контейнер](docs/ru/container.md) · [English](docs/en/container.md)
- [Роутинг](docs/ru/routing.md) · [English](docs/en/routing.md)
- [HTTP и ядро](docs/ru/http.md) · [English](docs/en/http.md)
- [Рантаймы: php-fpm, FrankenPHP, RoadRunner](docs/ru/runtime.md) · [English](docs/en/runtime.md)

Попробовать пример:

```bash
php -S 127.0.0.1:8080 -t examples/hello/public examples/hello/public/index.php
curl http://127.0.0.1:8080/hello/world
```

Требования: PHP >= 8.4.

```bash
composer install
composer test
composer stan
composer cs
```

Лицензия: MIT.
