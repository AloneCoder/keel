# Keel

[English version](README.md)

Небольшой worker-first микро-фреймворк на PHP: PSR-7/11/14/15/17 целиком, роутинг на
атрибутах с ограниченным discovery, компилируемые контейнер и роутер, и один и тот же код
под php-fpm, FrankenPHP worker mode и RoadRunner.

Статус: 0.1.0, ядро целиком на месте. См. [CHANGELOG.md](CHANGELOG.md). Дорожная карта — в документе с решениями.

- [Анализ рынка](docs/ru/analysis.md) · [English](docs/en/analysis.md)
- [Проектные решения и дорожная карта](docs/ru/design.md) · [English](docs/en/design.md)
- [Контейнер](docs/ru/container.md) · [English](docs/en/container.md)
- [Роутинг](docs/ru/routing.md) · [English](docs/en/routing.md)
- [HTTP и ядро](docs/ru/http.md) · [English](docs/en/http.md)
- [Рантаймы: php-fpm, FrankenPHP, RoadRunner](docs/ru/runtime.md) · [English](docs/en/runtime.md)
- [Приложение: конфиг, discovery, события, консоль](docs/ru/app.md) · [English](docs/en/app.md)

Попробовать пример:

```bash
php -S 127.0.0.1:8080 -t examples/hello/public examples/hello/public/index.php
curl http://127.0.0.1:8080/hello/world
php examples/hello/bin/console keel:routes
```

Требования: PHP >= 8.4.

```bash
composer install
composer test
composer stan
composer cs
```

## Бенчмарки

In-process, один маршрут (`GET /json/42`, JSON-тело), prod-режим с прогретыми кэшами,
PHP 8.4.21 с opcache, JIT выключен, ноутбук на Apple Silicon, 30 000 итераций после прогрева.
Цифра показывает накладные расходы фреймворка на запрос; SAPI и сеть исключены.
Воспроизвести: `cd benchmarks && composer install && php -d opcache.enable_cli=1 run.php`.

| Фреймворк | старт (мс) | мкс / запрос | запросов / с | память после старта |
|---|---|---|---|---|
| Keel 0.1.0 (скомпилированный контейнер, кэш роутов) | 11,8 | 4,8 | 208 000 | 0,21 МБ |
| Slim 4.15 (маршруты-замыкания, без контейнера) | 5,9 | 4,8 | 206 000 | 0,07 МБ |
| Symfony 8.1 MicroKernel (FrameworkBundle, `#[Route]`) | 29,5 | 31,0 | 32 000 | 0,52 МБ |

Память: prod-старт загружает 30 файлов (26 классов, 3 файла кэшей, 1 файл конфига), около
0,3 МБ кучи без opcache и заметно меньше 100 КБ с ним; объекты `Route` создаются только для
совпавших маршрутов, а после запроса не остаётся ничего: 2000 запросов подряд добавляют 0 байт.
`keel:preload` генерирует скрипт для `opcache.preload`, чтобы php-fpm линковал классы один раз: с ним
старт выделяет 4 КБ и загружает 3 файла, а старт плюс первый запрос падает с 12,8 до 2,7 мс.
Измерить: `php -d opcache.enable_cli=1 memory.php` и `memory-classes.php` в `benchmarks/`.

Keel в этой таблице делает на запрос больше, чем приложение на Slim (контроллер с
атрибутами, разрешение аргументов с приведением типов, обработчик Problem Details,
middleware из контейнера) за ту же цену. У Symfony в цифру входит полный цикл событий
HttpKernel и `terminate()`.

## Дорожная карта

Этапы M0–M5 из [документа с решениями](docs/ru/design.md) закрыты. Дальше: пакеты по
компонентам (`keel/container`, `keel/router`), HTML-страница ошибки для debug-режима,
эксперимент с рантаймом на Fibers и обратная связь от реальных проектов до 1.0.

Лицензия: MIT.
