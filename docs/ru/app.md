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
   окружение имеет приоритет над файлом. Читается один раз на процесс.
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

В prod с готовыми кэшами старт идёт по короткому пути: конфиги, кэш роутов, скомпилированный
контейнер и список слушателей из кэша discovery. `Definitions`, сканер, discovery-классы и
рефлексия не загружаются вовсе; `keel:compile` собирает всё это заново отдельным процессом.

Кэши prod не обновляются сами: после деплоя выполните `bin/console keel:compile` или
удалите `var/cache/<env>/`. В debug кэшируется только discovery, с проверкой по числу
файлов и максимальному mtime.

## Конфиг

Конфиг — обычный readonly-объект; сервисы получают его через конструктор по типу:

```php
// config/mailer.php
return static fn(Env $env): MailerConfig => new MailerConfig(dsn: $env->require('MAILER_DSN'));

final class Mailer { public function __construct(private MailerConfig $config) {} }
```

`Env`: `get`, `require`, `bool` (`1/true/yes/on`), `int`, `has`, `with`. Разбор `.env`:
`KEY=value`, кавычки одинарные и двойные (в двойных работают `\"`), комментарии `#`,
без подстановки переменных.

## Discovery

`Discoverer` сканирует только каталоги из `DiscoveryConfig::$paths`, никогда `vendor/`.
Файлы без `#[` пропускаются до токенизации; имена классов берутся из токенов без загрузки
файла, рефлексия создаётся только для классов, которые умеет загрузить автозагрузчик
(один класс на файл по PSR-4).

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

## Консоль

`ConsoleFactory::create($app)` возвращает Symfony Console Application c командами:

- `keel:routes` — таблица маршрутов;
- `keel:compile` — пересобрать `discovery.php`, `routes.php`, `container.php`;
- `keel:cache-clear` — удалить их;
- `keel:preload` — записать `var/cache/<env>/preload.php` для `opcache.preload`: все файлы, которые
  приложение загружает на старте и при запросе, в порядке загрузки. С preload классы линкуются один
  раз в общей памяти opcache, а не в каждом процессе php-fpm и не в каждом воркере; особенно это
  касается PSR-7 классов с трейтами, которые opcache иначе не разделяет. В php.ini:
  `opcache.preload=/path/to/var/cache/prod/preload.php`;
- все найденные `#[AsCommand]`, создаются лениво через контейнер (конструктор автовайрится).

`symfony/console` — опциональная зависимость (`composer require symfony/console`).
