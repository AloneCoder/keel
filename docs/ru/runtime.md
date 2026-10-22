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

- `Kernel::terminate($request, $response)` выполняется после каждого запроса, когда ответ уже
  ушёл: рассылает `ResponseSent`, выполняет отложенные задачи и вызывает `Container::reset()`,
  так что все созданные shared-сервисы с интерфейсом `Resettable` сбрасывают состояние запроса.
  Сброс выполняется в `finally`, даже если отправка ответа упала. Под php-fpm запрос сначала
  завершается для клиента (`fastcgi_finish_request()`); FrankenPHP вызывает его после возврата из
  обработчика запроса, RoadRunner — после того как ответ ушёл серверу.
- Ничего зависящего от запроса не хранится в статике, глобалах или свойствах сервисов,
  которые не `Resettable`. Запрос передаётся явно.
- Исключение при создании запроса или отправке ответа логируется как `critical` и не
  останавливает воркер; `Kernel::handle()` сам никогда не бросает.
- `MAX_REQUESTS` — страховка от утечек памяти в сторонних библиотеках: воркер завершится, и
  FrankenPHP/RoadRunner поднимут новый.
- `MemoryGuard` — вторая страховка: после каждого запроса рантаймы сравнивают память процесса
  (`memory_get_usage(true)`) с `KEEL_MAX_MEMORY` (байты или `256M`), по умолчанию 90% от
  `memory_limit`; выше лимита воркер пишет предупреждение с цифрами и останавливается, вместо
  того чтобы упасть посреди запроса с «Allowed memory size exhausted». `KEEL_MAX_MEMORY=0`
  выключает проверку. `MetricsMiddleware` отдаёт то же число как gauge `process_memory_bytes`, а
  `keel:doctor` показывает, какие лимиты есть у воркера.

Пример `examples/hello`: маршрут `/stats` показывает `request_hits` (всегда 1, сервис
`Resettable`) и `worker_hits` (растёт в worker-режиме, 1 под php-fpm).

## События жизненного цикла

Ядро и рантаймы рассылают PSR-14 события через диспетчер приложения (см.
[События](app.md#события-psr-14)); событие создаётся, только если на него есть слушатель, так
что события без слушателей стоят одного поиска в массиве на запрос.

| Событие (`Keel\Kernel\Event`) | Когда | Несёт |
|---|---|---|
| `RequestReceived` | запрос входит в конвейер, до глобальных middleware | `request` |
| `ExceptionRaised` | исключение вышло из конвейера, до его отрисовки | `exception`, `request`, `status` |
| `ResponseSent` | в `Kernel::terminate()`, после того как ответ ушёл | `request`, `response` |
| `WorkerStarted` | воркер FrankenPHP или RoadRunner, до первого запроса | `runtime` |
| `WorkerStopping` | цикл воркера закончился | `runtime`, `requests`, `reason`: `server`, `max_requests` или `memory` |

```php
final class ErrorReporter
{
    public function __construct(private readonly Tracker $tracker) {}

    #[Listener]
    public function report(ExceptionRaised $event): void
    {
        if ($event->status >= 500) {
            $this->tracker->capture($event->exception, ['path' => $event->request->getUri()->getPath()]);
        }
    }
}
```

Слушатель `RequestReceived`, который бросил исключение, обрабатывается как упавший хэндлер;
ошибка слушателя `ExceptionRaised` или `ResponseSent` логируется и ничего не меняет для клиента.
`WorkerStarted` и `WorkerStopping` не рассылаются под php-fpm, где каждый запрос — новый
процесс; исключение в слушателе `WorkerStarted` останавливает воркер до первого запроса.

## Работа после ответа

```php
#[Post('/signup')]
#[Status(201)]
public function signUp(#[FromBody] SignUp $form, DeferredTasks $deferred): array
{
    $user = $this->users->create($form);
    $deferred->defer(fn() => $this->mailer->sendWelcome($user));
    $deferred->defer(fn() => $this->crm->push($user));

    return ['id' => $user->id];
}
```

`DeferredTasks::defer()` ставит замыкание, которое `Kernel::terminate()` выполнит, когда ответ
уже у клиента: письмо, вебхук, прогрев кэша, запись аудита — без очереди. Задачи выполняются по
порядку; упавшая логируется, следующая всё равно выполняется; задача может отложить новые. Они не
надёжны (падение или деплой их теряет), а в worker-режиме воркер не берёт запрос, пока они не
закончатся, — держите их короткими, а работу, которую нельзя потерять, отдавайте настоящей
очереди. `TestClient` выполняет их до того, как вернуть ответ, как и воркер.

## Сброс нагрузки

```php
// config/load_shedding.php
return new LoadSheddingConfig(
    maxInFlight: 14,          // из 16 воркеров: остальные быстро отвечают 503, а не копят очередь
    maxQueueTime: 2_000,      // мс, которые запрос ждал перед PHP (X-Request-Start)
    maxLoadAverage: 12.0,
    minAvailableMemory: '256M',
);
```

`LoadSheddingMiddleware` отвечает 503 с `Retry-After` до всякой работы, пока сервер перегружен.
Быстрый 503 для части запросов сохраняет остальные, тогда как полная работа над всеми довела бы
их все до таймаута. Каждый сигнал выключен, пока не задан порог:

| Сигнал | Что читает | Заметки |
|---|---|---|
| `maxInFlight` | число запросов, которые прямо сейчас обрабатывают воркеры с общим APCu | пул php-fpm или сервер FrankenPHP; у воркеров RoadRunner APCu у каждого свой (там ограничивайте параллелизм размером пула). Счётчик начинается заново каждые 60 секунд, чтобы убитый посреди запроса воркер не оставил его завышенным навсегда; без APCu считается только этот процесс |
| `maxQueueTime` | `X-Request-Start` или `X-Queue-Start` от прокси: `t=<секунды>`, миллисекунды или микросекунды | nginx: `proxy_set_header X-Request-Start "t=${msec}";` (Heroku выставляет сам). Клиент может «состарить» только свой запрос |
| `maxLoadAverage` | load average хоста за минуту | |
| `minAvailableMemory` | внутри контейнера — лимит cgroup v2 минус его working set, иначе `MemAvailable` | Linux; пропускается, если ни то ни другое не читается; читается не чаще раза в секунду |

Пути из `except` (по умолчанию `/health` и `/metrics`) не сбрасываются никогда; кроме того,
ставьте middleware после `HealthMiddleware` и `MetricsMiddleware`. Каждый сброшенный запрос
логируется на уровне info с причиной. Это брат `MemoryGuard`: тот перезапускает разросшийся
воркер, а сброс нагрузки защищает сервер целиком.

## Проверено вживую

Пример прогнан под FrankenPHP 1.12.6 (встроенный PHP 8.5.8) в worker mode с `examples/hello/Caddyfile`:
один pid на все запросы, `request_hits` равен 1 в каждом ответе, `worker_hits` растёт, 300 запросов
подряд — все 200, ошибок в логе нет.

## Нагрузка

`benchmarks/frankenphp/run.sh` поднимает Keel и Slim 4 в FrankenPHP worker mode и обстреливает их `oha`.
На ноутбуке с Apple Silicon, 8 воркеров, 64 соединения, 10 с: Keel 8 486 запросов/с, Slim 8 603,
медиана 5,2 мс, p99 34 мс у обоих. Потолок задаёт HTTP-стек Caddy, а не фреймворк.

## Конфигурации примера

- `examples/hello/Caddyfile` — `frankenphp run --config examples/hello/Caddyfile`
  (worker mode, 2 воркера, `MAX_REQUESTS 1000`).
- `examples/hello/.rr.yaml` — `rr serve` из каталога примера.
- php-fpm и встроенный сервер — никакой конфигурации, кроме `root` на `public/`.
