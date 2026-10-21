# Рантаймы

Namespace `Keel\Runtime`. Один фронт-контроллер работает под php-fpm, FrankenPHP worker mode
и RoadRunner: рантайм выбирается при старте процесса.

```php
// public/index.php
$kernel = Kernel::build(new Definitions(), [HelloController::class]);
exit(Runtime::run($kernel));
```

## Выбор рантайма

`Runtime::detect()` смотрит, в этом порядке:

1. `KEEL_RUNTIME` (`fpm`, `frankenphp`, `roadrunner`) из `$_SERVER` или окружения — явный выбор;
2. функция `frankenphp_handle_request` и `$_SERVER['FRANKENPHP_WORKER']` — FrankenPHP worker mode;
3. `RR_MODE=http` — RoadRunner;
4. иначе `FpmRuntime`.

| Рантайм | Модель | Как запускается |
|---|---|---|
| `FpmRuntime` | процесс на запрос: php-fpm, mod_php, `php -S`, FrankenPHP без worker | `handle()` → `emit()` → `terminate()` один раз |
| `FrankenPhpRuntime` | долгоживущий воркер, запросы через `frankenphp_handle_request()` | цикл до `MAX_REQUESTS` (аргумент или `$_SERVER['MAX_REQUESTS']`), после каждого запроса `terminate()` и `gc_collect_cycles()` |
| `RoadRunnerRuntime` | долгоживущий воркер, PSR-7 через `spiral/roadrunner-http` | цикл `waitRequest()` → `handle()` → `respond()`, пока RoadRunner не остановит воркер |

`spiral/roadrunner-http` — опциональная зависимость; без неё `RoadRunnerRuntime` бросит
понятное исключение при старте.

## Память на старте

Prod-старт с готовыми кэшами загружает около 30 файлов и не создаёт ни определений, ни
рефлексии; объекты `Route` материализуются по мере совпадений, слушатели событий скомпилированы
в контейнер. Под php-fpm добавьте `opcache.preload` со скриптом из `keel:preload`: иначе каждый
процесс заново линкует классы с трейтами (PSR-7 сообщения nyholm/psr7), это порядка 100 КБ на
процесс и заметная доля времени первого запроса. Замер на примере из `benchmarks/`: без preload
старт 72 КБ и 30 файлов, с preload 4 КБ и 3 файла; старт плюс первый запрос 12,8 мс против 2,7 мс.

## Что происходит между запросами

В worker-режимах один и тот же `Kernel`, контейнер и роутер обслуживают тысячи запросов.
Правила, которых придерживается ядро и которых должно придерживаться приложение:

- `Kernel::terminate()` после каждого запроса вызывает `Container::reset()`: все созданные
  shared-сервисы с интерфейсом `Resettable` сбрасывают состояние запроса. Это выполняется
  в `finally`, даже если отправка ответа упала.
- Ничего зависящего от запроса не хранится в статике, глобалах или свойствах сервисов,
  которые не `Resettable`. Запрос передаётся явно.
- Исключение при создании запроса или отправке ответа логируется как `critical` и не
  останавливает воркер; `Kernel::handle()` сам никогда не бросает.
- `MAX_REQUESTS` — страховка от утечек памяти в сторонних библиотеках: воркер завершится, и
  FrankenPHP/RoadRunner поднимут новый.

Пример `examples/hello`: маршрут `/stats` показывает `request_hits` (всегда 1, сервис
`Resettable`) и `worker_hits` (растёт в worker-режиме, 1 под php-fpm).

## Проверено вживую

Пример прогнан под FrankenPHP 1.12.6 (встроенный PHP 8.5.8) в worker mode с `examples/hello/Caddyfile`:
один pid на все запросы, `request_hits` равен 1 в каждом ответе, `worker_hits` растёт, 300 запросов
подряд — все 200, ошибок в логе нет.

## Конфигурации примера

- `examples/hello/Caddyfile` — `frankenphp run --config examples/hello/Caddyfile`
  (worker mode, 2 воркера, `MAX_REQUESTS 1000`).
- `examples/hello/.rr.yaml` — `rr serve` из каталога примера.
- php-fpm и встроенный сервер — никакой конфигурации, кроме `root` на `public/`.
