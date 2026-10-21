# Keel

[English version](README.md)

Небольшой worker-first микро-фреймворк на PHP: PSR-7/11/14/15/17 целиком, роутинг на
атрибутах с ограниченным discovery, компилируемые контейнер и роутер, и один и тот же код
под php-fpm, FrankenPHP worker mode и RoadRunner.

Статус: 1.2.1, API заморожен под семантическое версионирование. Сайт документации: https://alonecoder.github.io/keel/ru/ Начать проект: `composer create-project keel/skeleton my-app`. См. [CHANGELOG.md](CHANGELOG.md) и дорожную карту ниже.

- [Контейнер](docs/ru/container.md) · [English](docs/en/container.md)
- [Роутинг](docs/ru/routing.md) · [English](docs/en/routing.md)
- [HTTP и ядро](docs/ru/http.md) · [English](docs/en/http.md)
- [Рантаймы: php-fpm, FrankenPHP, RoadRunner](docs/ru/runtime.md) · [English](docs/en/runtime.md)
- [Приложение: конфиг, discovery, события, консоль](docs/ru/app.md) · [English](docs/en/app.md)
- [Тестирование](docs/ru/testing.md) · [English](docs/en/testing.md)
- [Сессии и подписанные cookie](docs/ru/session.md) · [English](docs/en/session.md)
- [Аутентификация и лимиты](docs/ru/auth.md) · [English](docs/en/auth.md)
- [OpenAPI](docs/ru/openapi.md) · [English](docs/en/openapi.md)
- [Здоровье и метрики](docs/ru/observability.md) · [English](docs/en/observability.md)
- [База данных](docs/ru/database.md), [Шаблоны](docs/ru/views.md), [Кэш](docs/ru/cache.md), [HTTP-клиент](docs/ru/http-client.md) · [English](docs/en/database.md)
- [Стабильность API](docs/ru/api.md) · [English](docs/en/api.md)

Попробовать пример:

```bash
php -S 127.0.0.1:8080 -t examples/hello/public examples/hello/public/index.php
curl http://127.0.0.1:8080/hello/world
php examples/hello/bin/console keel:routes
```

В консоли есть `keel:routes`, `keel:compile`, `keel:cache-clear`, `keel:debug:container`, `keel:doctor`,
`keel:preload`, `keel:openapi`, `keel:serve` (встроенный сервер или worker mode FrankenPHP с
`--watch`) и генераторы `keel:make:*` для контроллеров, команд, middleware, слушателей,
проверок здоровья и аутентификаторов; см. [Приложение](docs/ru/app.md).

Требования: PHP >= 8.4.

```bash
composer install
composer test
composer stan
composer cs
composer infection
```

CI запускает тесты на PHP 8.4 и 8.5, PHPStan на уровне max, php-cs-fixer и Infection для
контейнера, роутера, аутентификации, лимитов, здоровья и метрик (порог MSI 80%).

## Пакеты

`keel/framework` — всё целиком. Четыре компонента публикуются и отдельно, как read-only срезы этого
репозитория, которые фреймворк `replace`-ит: [keel/container](https://github.com/AloneCoder/keel-container),
[keel/router](https://github.com/AloneCoder/keel-router), [keel/http](https://github.com/AloneCoder/keel-http)
(зависит от router и validation) и [keel/validation](https://github.com/AloneCoder/keel-validation).
Issue и pull request — сюда; `bin/split vX.Y.Z` пушит срезы.

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

Под реальным сервером: FrankenPHP 1.12 worker mode (8 воркеров), `oha` с 64 соединениями 10 с на том
же ноутбуке, тот же JSON-маршрут: Keel 8 486 запросов/с, Slim 8 603 запросов/с, медиана 5,2 мс и
p99 34 мс у обоих, все ответы 200. Воспроизвести: `benchmarks/frankenphp/run.sh`.

Keel в этой таблице делает на запрос больше, чем приложение на Slim (контроллер с
атрибутами, разрешение аргументов с приведением типов, обработчик Problem Details,
middleware из контейнера) за ту же цену. У Symfony в цифру входит полный цикл событий
HttpKernel и `terminate()`.

## Дорожная карта

1.0 закрыл ядро; 1.1 добавил то, что нужно API в продакшене: аутентификацию (`#[Auth]`, bearer,
API-ключ, HMAC), `#[RateLimit]`, эндпоинты здоровья, метрики Prometheus, документ OpenAPI 3.1,
выводимый из кода, и `keel:serve` для worker mode FrankenPHP в разработке. 1.2 подключил привычных
соседей: PDO или Doctrine DBAL с проверками здоровья, Twig или Latte, PSR-16 кэш без зависимостей
и PSR-18 клиент на curl; 1.2.1 добавил генераторы проверок здоровья и аутентификаторов и
расширил мутационное тестирование. Дальше решает обратная связь от реальных проектов; очереди и планировщик
остаются за рамками.

Лицензия: MIT.
