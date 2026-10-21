# Application: config, discovery, events, console

`Keel\App\App` assembles an application from a project directory by convention. Everything
else (container, router, kernel, runtimes) can be used without it.

## Project layout

```
config/        *.php, each returning a config object or a closure (Env, string $projectDir): object
src/           scanned by discovery: controllers, attributed services, listeners, commands
public/index.php
bin/console
var/cache/<env>/   discovery.php, routes.php, container.php
.env           optional
```

```php
// public/index.php
exit(App::boot(dirname(__DIR__))->run());

// bin/console
exit(ConsoleFactory::create(App::boot(dirname(__DIR__)))->run());
```

`App::boot($projectDir, ?Env $env, ?\Closure $configure)`:

1. `Env`: a snapshot of `getenv()`, `$_ENV`, `$_SERVER` plus `.env` when the file exists;
   the real environment wins over the file. Read once per process.
2. `config/*.php` → objects registered in the container under their class. Known to the
   core: `AppConfig` (env, debug, cacheDir), `HttpConfig` (global middleware),
   `DiscoveryConfig` (scanned directories, `src/` by default), `ServicesConfig`
   (list of `ServiceProvider`s). Missing ones get defaults.
3. Discovery (below), then `Definitions`: `Env`, every config, providers, attributed
   services, `ListenerProvider`/`EventDispatcher`, kernel defaults, the `$configure` closure.
4. Router: in debug built from controllers on every boot, in prod loaded from
   `routes.php` (written on first boot).
5. Container: in debug the reflection `Container`, in prod the compiled class from
   `container.php` (written on first boot). The class name embeds a hash of project and
   environment, so several apps can live in one process.
6. `Kernel` with the middleware from `HttpConfig`.

In production with warm caches boot takes the short path: config objects, the route cache,
the compiled container and the listener list from the discovery cache. `Definitions`, the
scanner, the discovery classes and reflection are never loaded; `keel:compile` rebuilds all
of it in a separate process.

Production caches do not refresh themselves: after a deploy run `bin/console keel:compile`
or delete `var/cache/<env>/`. In debug only discovery is cached, validated by file count
and newest mtime.

## Config

A config is a plain readonly object; services receive it through the constructor by type:

```php
// config/mailer.php
return static fn(Env $env): MailerConfig => new MailerConfig(dsn: $env->require('MAILER_DSN'));

final class Mailer { public function __construct(private MailerConfig $config) {} }
```

`Env`: `get`, `require`, `bool` (`1/true/yes/on`), `int`, `has`, `with`. `.env` parsing:
`KEY=value`, single and double quotes (`\"` works inside double quotes), `#` comments,
no variable expansion.

## Discovery

`Discoverer` scans only the directories in `DiscoveryConfig::$paths`, never `vendor/`.
Files without `#[` are skipped before tokenizing; class names are read from tokens without
loading the file, and reflection is created only for classes the autoloader can load
(one class per file, PSR-4).

Built-in discoveries:

| Class | Finds | Feeds |
|---|---|---|
| `RouteDiscovery` | classes with methods carrying `#[Route]`/`#[Get]`/… | router controllers |
| `ServiceDiscovery` | classes with `#[Alias]`, `#[Tag]`, `#[Lazy]`, `#[Transient]`, `#[Shared]` | `Definitions::set()`, so aliases and tags work |
| `ListenerDiscovery` | public methods with `#[Listener]` | `ListenerProvider` |
| `CommandDiscovery` | `Symfony\Component\Console\Command\Command` subclasses with `#[AsCommand]` | console |

A custom discovery implements `DiscoveryInterface` (`discover(ReflectionClass)`, `result()`,
`restore(array)`) and is added with `Discoverer::add()`.

## Events (PSR-14)

```php
final class GreetingLog
{
    #[Listener(priority: 10)]
    public function onGreeted(Greeted $event): void { /* ... */ }
}

$this->events->dispatch(new Greeted($name));   // EventDispatcherInterface from the container
```

The event type is the type of the first parameter or `#[Listener(event: Foo::class)]`.
Listeners on a parent class or an interface receive subclasses too. Order: priority
descending, then registration order. `StoppableEventInterface` stops the chain. The listener
object is created by the container on the first event, so it can have dependencies.

## Console

`ConsoleFactory::create($app)` returns a Symfony Console Application with:

- `keel:routes`: the route table;
- `keel:compile`: rebuild `discovery.php`, `routes.php`, `container.php`;
- `keel:cache-clear`: remove them;
- `keel:preload`: write `var/cache/<env>/preload.php` for `opcache.preload`: every file the
  application loads to boot and answer a request, in load order. With preloading those classes
  are linked once in opcache shared memory instead of once per php-fpm request or per worker;
  this matters most for the trait-based PSR-7 classes, which opcache cannot share otherwise.
  In php.ini: `opcache.preload=/path/to/var/cache/prod/preload.php`;
- every discovered `#[AsCommand]`, created lazily through the container (autowired constructor).

`symfony/console` is an optional dependency (`composer require symfony/console`).
