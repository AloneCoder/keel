# Приложение: конфиг, discovery, события, консоль

`Keel\App\App` собирает приложение из каталога проекта по конвенциям. Всё остальное
(контейнер, роутер, ядро, рантаймы) можно использовать и без него.

## Структура проекта

```
config/        *.php, каждый возвращает объект конфига или замыкание (Env, string $projectDir): object
src/           сканируется discovery: контроллеры, сервисы с атрибутами, слушатели, команды
public/index.php
bin/console
var/cache/<env>/   discovery.php, routes.php, container.php
.env           необязательно
```

```php
// public/index.php
exit(App::boot(dirname(__DIR__))->run());

// bin/console
exit(ConsoleFactory::create(App::boot(dirname(__DIR__)))->run());
```

`App::boot($projectDir, ?Env $env, ?\Closure $configure)`:

1. `Env`: снимок `getenv()`, `$_ENV`, `$_SERVER` плюс `.env`, если файл есть; реальное
   окружение имеет приоритет над файлом. Читается один раз на процесс. Переменные запроса из
   `$_SERVER` (`HTTP_*`, `REDIRECT_*`, `REQUEST_URI`, `REMOTE_ADDR`, ...) не берутся, если их
   нет в `getenv()` или `$_ENV`: под php-fpm они описывают один запрос, а не процесс.
2. `config/*.php` → объекты, зарегистрированные в контейнере под своим классом. Известные
   ядру: `AppConfig` (env, debug, cacheDir), `HttpConfig` (глобальные middleware),
   `DiscoveryConfig` (каталоги сканирования, по умолчанию `src/`), `ServicesConfig`
   (список `ServiceProvider`). Отсутствующие создаются с дефолтами.
3. Discovery (см. ниже), затем `Definitions`: `Env`, все конфиги, провайдеры, сервисы с
   атрибутами, `ListenerProvider`/`EventDispatcher`, дефолты ядра, замыкание `$configure`.
4. Роутер: в debug строится из контроллеров на каждый запуск, в prod читается из
   `routes.php` (создаётся при первом запуске).
5. Контейнер: в debug — `Container` на рефлексии, в prod — скомпилированный класс из
   `container.php` (создаётся при первом запуске). Имя класса включает хэш проекта и
   окружения, поэтому несколько приложений уживаются в одном процессе.
6. `Kernel` с middleware из `HttpConfig`.

Старт из кэша передаёт скомпилированному контейнеру только `Env`, объекты конфигов и
`Router`, поэтому `Definitions::instance()` в провайдере (или `$configure`) для чего-то ещё
сработал бы один раз и ронял бы каждый следующий запуск. Компиляция таких определений
отклоняется с `ContainerException`; соберите объект в статической фабрике
(`$d->set($id, Foo::class)->factory(FooFactory::class . '::create')`) или верните его из файла
`config/*.php`.

В prod с готовыми кэшами старт идёт по короткому пути: конфиги, кэш роутов, скомпилированный
контейнер и список слушателей из кэша discovery. `Definitions`, сканер, discovery-классы и
рефлексия не загружаются вовсе; `keel:compile` собирает всё это заново отдельным процессом.

Кэши prod не обновляются сами: после деплоя выполните `bin/console keel:compile` или
удалите `var/cache/<env>/`. В debug кэшируется только discovery, с проверкой по числу
файлов и максимальному mtime (проход `stat()`; пока ничего не изменилось, файлы не читаются).

PHP не умеет перезагружать класс: если `container.php` переписан в процессе, который его уже
загрузил (`App::compile()` и следом `App::boot()`, долгоживущий worker), следующий запуск
сравнивает файл с `CONTENT_HASH` загруженного класса и бросает `RuntimeException` с просьбой
начать новый процесс вместо того, чтобы молча обслуживать старый контейнер.

## Конфиг

Конфиг — обычный readonly-объект; сервисы получают его через конструктор по типу:

```php
// config/mailer.php
return static fn(Env $env): MailerConfig => new MailerConfig(dsn: $env->require('MAILER_DSN'));

final class Mailer { public function __construct(private MailerConfig $config) {} }
```

`Env`: `get`, `require`, `bool` (`1/true/yes/on`), `int`, `has`, `with`. Разбор `.env`:
`KEY=value`, кавычки одинарные и двойные (в двойных работают `\"`; значение в кавычках
заканчивается на закрывающей кавычке, всё после неё — комментарий), комментарии ` #` после
значений без кавычек, без подстановки переменных.

## Discovery

`Discoverer` сканирует только каталоги из `DiscoveryConfig::$paths`, никогда `vendor/`.
Файлы без `#[` и без `extends` пропускаются до токенизации (наследник получает методы с
атрибутами от родителя, не повторяя их); имена классов берутся из токенов без загрузки
файла, рефлексия создаётся только для классов, которые умеет загрузить автозагрузчик
(один класс на файл по PSR-4). Класс, который не загружается — например, его родитель из
неустановленного пакета, — пропускается, а не роняет запуск. Кэш проверяется при
восстановлении: методы слушателей должны существовать, команды — наследовать `Command`,
а `#[Listener(event: ...)]` — называть существующий класс или интерфейс.

Встроенные discovery:

| Класс | Что ищет | Куда попадает |
|---|---|---|
| `RouteDiscovery` | классы с методами, помеченными `#[Route]`/`#[Get]`/… | контроллеры роутера |
| `ServiceDiscovery` | классы с `#[Alias]`, `#[Tag]`, `#[Lazy]`, `#[Transient]`, `#[Shared]` | `Definitions::set()`, чтобы алиасы и теги работали |
| `ListenerDiscovery` | публичные методы с `#[Listener]` | `ListenerProvider` |
| `CommandDiscovery` | наследники `Symfony\Component\Console\Command\Command` с `#[AsCommand]` | консоль |

Своя discovery: реализуйте `DiscoveryInterface` (`discover(ReflectionClass)`, `result()`,
`restore(array)`) и добавьте в `Discoverer::add()`.

## События (PSR-14)

```php
final class GreetingLog
{
    #[Listener(priority: 10)]
    public function onGreeted(Greeted $event): void { /* ... */ }
}

$this->events->dispatch(new Greeted($name));   // EventDispatcherInterface из контейнера
```

Тип события — тип первого параметра или `#[Listener(event: Foo::class)]`. Слушатели на
родительский класс или интерфейс получают и наследников. Порядок: приоритет по убыванию,
затем порядок регистрации. `StoppableEventInterface` останавливает цепочку. Объект-слушатель
создаётся контейнером при первом событии, поэтому у него могут быть зависимости.

## Логирование

Если приложение не зарегистрировало `Psr\Log\LoggerInterface`, `App::boot()` подключает
`Keel\Log\StreamLogger`: PSR-3 без зависимостей, одна JSON-строка на запись в `php://stderr`
(их собирают FrankenPHP, RoadRunner и Docker). Настройки — `LoggerConfig` в `config/logger.php`.
Исключения в контексте превращаются в класс, сообщение и место; `{placeholders}` подставляются
из контекста.

```php
// config/logger.php
return static fn(string $projectDir): LoggerConfig => new LoggerConfig(
    stream: 'php://stderr', level: 'debug', channel: 'app', json: true,   // канал по умолчанию
    channels: [
        'audit' => new ChannelConfig(stream: $projectDir . '/var/log/audit.log', level: 'info', rotate: 'daily'),
        'security' => new ChannelConfig(syslog: 'myapp'),                 // локальный syslog под этим ident
    ],
);
```

- **Каналы.** `Loggers::channel('audit')` — PSR-3-логгер именованного канала; каждый
  `ChannelConfig` перекрывает только те умолчания, что задаёт (поток, уровень, JSON или текст,
  ротация, syslog). Канал по умолчанию — `LoggerInterface` контейнера.
- **Ротация.** `rotate: 'daily'` превращает файловый поток `var/log/app.log` в
  `var/log/app-2026-10-22.log` и переоткрывает его при смене даты; старые файлы удаляйте сами.
- **Контекст запроса.** `LogContextMiddleware`, указанный в `HttpConfig` после
  `RequestIdMiddleware`, `TrustedProxiesMiddleware` и `AuthMiddleware`, заполняет `LogContext`
  полями `request_id`, `method`, `path`, `client_ip` и `principal`; каждая запись запроса несёт
  их (собственный контекст записи побеждает). `LogContext::set()` добавляет свои (`tenant`);
  контейнер сбрасывает его между запросами. `context: false` в конфиге отключает слияние.
- **Monolog.** Загрузите `MonologProvider` (`composer require monolog/monolog`), и тот же
  конфиг управляет Monolog: по `Monolog\Logger` на канал со `StreamHandler`,
  `RotatingFileHandler` или `SyslogHandler`, `JsonFormatter` или `LineFormatter`, контекст
  запроса в `extra`; `LoggerInterface` и `Loggers` резолвятся в логгеры Monolog. Любой другой
  PSR-3-логгер по-прежнему подключается алиасом `LoggerInterface` в провайдере.

## Консоль

`ConsoleFactory::create($app)` возвращает Symfony Console Application c командами:

- `keel:routes` — таблица маршрутов с middleware, ролями `#[Auth]` и настройками `#[RateLimit]`;
- `keel:compile` — пересобрать `discovery.php`, `routes.php`, `container.php`;
- `keel:cache-clear` — удалить их;
- `keel:debug:container [filter]` — таблица сервисов, алиасов, параметров и экземпляров, из
  которых собирается контейнер;
- `keel:doctor [--skip-health]` — осмотр установки: PHP и OPcache, расширения apcu, curl и pdo,
  `APP_ENV`/`APP_DEBUG`, `config/` и пути discovery, каталог кэша и его файлы, рантайм, который
  выберет `KEEL_RUNTIME`, и лимиты воркеров (`MAX_REQUESTS`, `KEEL_MAX_MEMORY`), необязательные
  пакеты, контейнер (прогон всех сервисов «вхолостую», см. [Контейнер](container.md#ошибки), плюс
  компиляция в памяти), маршруты, каталоги сессий и кэша и зарегистрированные проверки здоровья.
  `OK`, `WARN` или `FAIL` на строку; код выхода 1, если что-то упало, так что команда уместна в
  пайплайне деплоя. `--skip-health` пропускает проверки здоровья, когда они ходят в базу;
- `keel:make:controller`, `keel:make:command`, `keel:make:middleware`, `keel:make:listener`,
  `keel:make:check` (проверка здоровья с тегом), `keel:make:authenticator` (`PrincipalResolver`,
  а с `--scheme` — полный `Authenticator`), `keel:make:policy Post [abilities...]` (`Policy` с
  тегом для `Gate`) — генераторы классов в namespace проекта
  (из `composer.json`), без перезаписи без `--force`;
- `keel:preload` — записать `var/cache/<env>/preload.php` для `opcache.preload`: все файлы, которые
  приложение загружает на старте и при запросе, в порядке загрузки. С preload классы линкуются один
  раз в общей памяти opcache, а не в каждом процессе php-fpm и не в каждом воркере; особенно это
  касается PSR-7 классов с трейтами, которые opcache иначе не разделяет. В php.ini:
  `opcache.preload=/path/to/var/cache/prod/preload.php`;
- `keel:openapi [--output=FILE]` — документ OpenAPI 3.1, см. [OpenAPI](openapi.md);
- `keel:serve [--host] [--port] [--docroot] [--frankenphp] [--worker] [--watch]` — сервер для
  разработки. По умолчанию встроенный сервер PHP; `--frankenphp` использует бинарник FrankenPHP
  (`$FRANKENPHP_BINARY` или `frankenphp` в `PATH`), `--worker` — его worker mode, `--watch`
  перезапускает воркеры при изменении `src/`, `config/` или `.env`. Worker mode в разработке —
  самый быстрый способ поймать состояние, утекающее между запросами;
- все найденные `#[AsCommand]`, создаются лениво через контейнер (конструктор автовайрится).

`symfony/console` — опциональная зависимость (`composer require symfony/console`).
