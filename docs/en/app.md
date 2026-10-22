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
   the real environment wins over the file. Read once per process. Request variables in
   `$_SERVER` (`HTTP_*`, `REDIRECT_*`, `REQUEST_URI`, `REMOTE_ADDR`, ...) are left out unless
   `getenv()` or `$_ENV` also has them: under php-fpm they describe one request, not the process.
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

A cached boot re-supplies only `Env`, the config objects and `Router` to the compiled
container, so a provider (or `$configure`) calling `Definitions::instance()` for anything else
would work once and fail on every later boot. Compiling such definitions is refused with a
`ContainerException`; build the object in a static factory
(`$d->set($id, Foo::class)->factory(FooFactory::class . '::create')`) or return it from a
`config/*.php` file instead.

In production with warm caches boot takes the short path: config objects, the route cache,
the compiled container and the listener list from the discovery cache. `Definitions`, the
scanner, the discovery classes and reflection are never loaded; `keel:compile` rebuilds all
of it in a separate process.

Production caches do not refresh themselves: after a deploy run `bin/console keel:compile`
or delete `var/cache/<env>/`. In debug only discovery is cached, validated by file count
and newest mtime (a `stat()` pass; no file is read while nothing changed).

PHP cannot reload a class: when `container.php` is rewritten in a process that already loaded
it (`App::compile()` followed by `App::boot()`, a long-lived worker), the next boot compares
the file with the loaded class's `CONTENT_HASH` and throws a `RuntimeException` asking for a
new process instead of silently serving the old container.

## Config

A config is a plain readonly object; services receive it through the constructor by type:

```php
// config/mailer.php
return static fn(Env $env): MailerConfig => new MailerConfig(dsn: $env->require('MAILER_DSN'));

final class Mailer { public function __construct(private MailerConfig $config) {} }
```

`Env`: `get`, `require`, `bool` (`1/true/yes/on`), `int`, `has`, `with`. `.env` parsing:
`KEY=value`, single and double quotes (`\"` works inside double quotes; a quoted value ends at
its closing quote, anything after it is a comment), ` #` comments after unquoted values,
no variable expansion.

## Discovery

`Discoverer` scans only the directories in `DiscoveryConfig::$paths`, never `vendor/`.
Files without `#[` or `extends` are skipped before tokenizing (a subclass inherits the
attributed methods of its parent without repeating them); class names are read from tokens
without loading the file, and reflection is created only for classes the autoloader can load
(one class per file, PSR-4). A class that fails to load, say because its parent comes from a
package that is not installed, is skipped rather than aborting the boot. Cached results are
validated on restore: listener methods must exist, commands must extend `Command`, and
`#[Listener(event: ...)]` must name an existing class or interface.

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

The kernel and the runtimes dispatch their own events on the same bus: `RequestReceived`,
`ExceptionRaised`, `ResponseSent`, `WorkerStarted` and `WorkerStopping`, see
[Runtimes](runtime.md#lifecycle-events).

## Logging

Unless the application registers a `Psr\Log\LoggerInterface`, `App::boot()` wires
`Keel\Log\StreamLogger`: dependency-free PSR-3, one JSON line per record to `php://stderr`
(collected by FrankenPHP, RoadRunner and Docker). Settings come from `LoggerConfig` in
`config/logger.php`. Exceptions in the context become class, message and location;
`{placeholders}` are interpolated from the context.

```php
// config/logger.php
return static fn(string $projectDir): LoggerConfig => new LoggerConfig(
    stream: 'php://stderr', level: 'debug', channel: 'app', json: true,   // the default channel
    channels: [
        'audit' => new ChannelConfig(stream: $projectDir . '/var/log/audit.log', level: 'info', rotate: 'daily'),
        'security' => new ChannelConfig(syslog: 'myapp'),                 // the local syslog under this ident
    ],
);
```

- **Channels.** `Loggers::channel('audit')` is the PSR-3 logger of a named channel; each
  `ChannelConfig` overrides the defaults it sets (stream, level, JSON or text, rotation,
  syslog). The default channel is the container's `LoggerInterface`.
- **Rotation.** `rotate: 'daily'` turns a file stream `var/log/app.log` into
  `var/log/app-2026-10-22.log`, reopened when the date changes; delete old files yourself.
- **Request context.** `LogContextMiddleware`, listed in `HttpConfig` after
  `RequestIdMiddleware`, `TrustedProxiesMiddleware` and `AuthMiddleware`, fills `LogContext`
  with `request_id`, `method`, `path`, `client_ip` and `principal`; every record of the request
  carries them (a record's own context wins). `LogContext::set()` adds your own (`tenant`); the
  container resets it between requests. `context: false` in the config turns the merge off.
- **Monolog.** Load `MonologProvider` (`composer require monolog/monolog`) and the same config
  drives Monolog: one `Monolog\Logger` per channel with a `StreamHandler`, `RotatingFileHandler`
  or `SyslogHandler`, `JsonFormatter` or `LineFormatter`, the request context in `extra`;
  `LoggerInterface` and `Loggers` resolve to Monolog loggers. Any other PSR-3 logger still plugs
  in by aliasing `LoggerInterface` in a provider.

## Console

`ConsoleFactory::create($app)` returns a Symfony Console Application with:

- `keel:routes`: the route table with middleware, `#[Auth]` roles and `#[RateLimit]` settings;
- `keel:compile`: rebuild `discovery.php`, `routes.php`, `container.php`;
- `keel:cache-clear`: remove them;
- `keel:debug:container [filter]`: table of the services, aliases, parameters and instances the
  container is built from;
- `keel:doctor [--skip-health]`: a check-up of the installation: PHP and OPcache, the apcu, curl
  and pdo extensions, `APP_ENV`/`APP_DEBUG`, `config/` and the discovery paths, the cache directory
  and its files, the runtime `KEEL_RUNTIME` selects and its worker limits (`MAX_REQUESTS`,
  `KEEL_MAX_MEMORY`), optional packages, the container (a dry run of every service, see
  [Container](container.md#errors), plus a compilation in memory), routes, session and cache
  directories, and the registered health checks. `OK`, `WARN` or `FAIL` per line; the exit code
  is 1 when anything failed, so it fits a deploy pipeline. `--skip-health` leaves the health
  checks out when they would reach a database;
- `keel:make:controller`, `keel:make:command`, `keel:make:middleware`, `keel:make:listener`,
  `keel:make:check` (a tagged health check), `keel:make:authenticator` (a `PrincipalResolver`, or
  with `--scheme` a full `Authenticator`), `keel:make:policy Post [abilities...]` (a tagged
  `Policy` for the `Gate`): class generators in the project namespace (from
  `composer.json`), never overwriting without `--force`;
- `keel:preload`: write `var/cache/<env>/preload.php` for `opcache.preload`: every file the
  application loads to boot and answer a request, in load order. With preloading those classes
  are linked once in opcache shared memory instead of once per php-fpm request or per worker;
  this matters most for the trait-based PSR-7 classes, which opcache cannot share otherwise.
  In php.ini: `opcache.preload=/path/to/var/cache/prod/preload.php`;
- `keel:openapi [--output=FILE]`: the OpenAPI 3.1 document, see [OpenAPI](openapi.md);
- `keel:serve [--host] [--port] [--docroot] [--frankenphp] [--worker] [--watch]`: a development
  server. By default PHP's built-in server; `--frankenphp` uses the FrankenPHP binary
  (`$FRANKENPHP_BINARY` or `frankenphp` in `PATH`), `--worker` its worker mode, `--watch` restarts
  the workers when `src/`, `config/` or `.env` change. Worker mode in development is the fastest
  way to catch state leaking between requests;
- `keel:install [component...] [--dry-run] [--no-composer] [--force]`: adds a framework
  component in one step: `composer require` for its packages (those not installed yet), its
  provider appended to `ServicesConfig` in `config/services.php` and its middleware to
  `HttpConfig` in `config/http.php` (edited in place, comments and layout kept; created when
  missing), and a starter config file for what it needs (`config/redis.php`, `config/jwt.php`,
  ...), never overwriting one that exists without `--force`. Without arguments it lists the
  components: `cache`, `redis`, `memcached`, `dbal`, `pdo`, `twig`, `latte`, `monolog`,
  `storage`, `flysystem`, `i18n`, `http-client`, `jwt`, `security-headers`, `cors`, `health`,
  `metrics`, `openapi`, `opentelemetry`, `roadrunner`, `console`. A component may pull others in
  (`jwt` brings `http-client` and `cache`); a list it cannot find in a config file is reported
  and has to be edited by hand (exit code 1);
- `keel:ai:init [--force] [--agents=AGENTS.md] [--llms=public/llms.txt] [--stdout]`: writes an
  `AGENTS.md` for coding agents, built from the application (stack, commands, layout, the worker
  mode rules, global middleware, providers, config objects, routes and pointers to the Keel docs
  installed in `vendor/`), and an `llms.txt` (llmstxt.org) describing the HTTP API from its
  OpenAPI document, served from `public/`. `--llms=none` or `--agents=none` skips one;
- every discovered `#[AsCommand]`, created lazily through the container (autowired constructor).

`symfony/console` is an optional dependency (`composer require symfony/console`).
