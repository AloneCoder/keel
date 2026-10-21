# Keel — design decisions

Name: **Keel** (keel — the minimal load-bearing structure). Package `keel/framework`,
namespace `Keel\`. The name was approved on 2026-10-21; the vendor `keel` on Packagist and the
`keel` organization on GitHub were available on that date.

This document records decisions with their rationale (ADR format in miniature). The rationale
is based on [analysis.md](analysis.md).

## 1. Goals and non-goals

**Goals**
- A micro-framework for HTTP APIs and small services on PHP ≥ 8.4.
- Worker-first: the same code runs without changes under php-fpm, FrankenPHP worker and
  RoadRunner.
- The full PSR stack: 7, 11, 14, 15, 17, 3, 20. Any PSR-15 middleware and PSR-11 container
  from the ecosystem plugs in without adapters.
- Attributes for routing, middleware, commands and listeners; discovery with an explicitly
  bounded scope and a cache.
- Compilation of the container and routes into PHP files; in dev — runtime reflection without
  a cache.
- A small core: target of 3–4 thousand lines and no more than five runtime dependencies.
- Performance on par with Symfony MicroKernel in FrankenPHP worker (reference from Tempest
  issue #2309: on the order of 25k rps on a simple JSON endpoint on a single machine).

**Non-goals (first version)**
- ORM, template engine, auth, sessions, queues, a Fiber-based async runtime, ACL. These are
  separate packages on top of the core, if they turn out to be needed.
- Support for Swoole/OpenSwoole as a target runtime. Possible later via an adapter.
- Backward compatibility with PHP < 8.4.

## 2. Key decisions

### D1. Minimum PHP 8.4, 8.5 features via feature detection
8.4 — 32 % of installations, 8.5 — 16 %. Lazy objects and property hooks are in 8.4. `ext/uri`,
`clone with` and closures in attributes are used only when `PHP_VERSION_ID >= 80500`,
with a fallback. Revisit the minimum (raise to 8.5) at the end of 2027.

### D2. Use ready-made: PSR-7/17, logging, console
- `nyholm/psr7` + `nyholm/psr7-server` — the only runtime dependency besides `psr/*`.
  Replaceable by any PSR-17 factory via the container.
- `monolog/monolog` and `symfony/console` — `suggest`, not `require`. The core works without
  them.
- We do not write our own PSR-7, logger or console.

### D3. Write ourselves: container, router, pipeline, kernel, discovery, config, events
The reasons for each component are in section 3. The general argument: the ready-made options
either do not compile (php-di for autowired classes), or are heavy (symfony/*), or are
outdated (FastRoute 1.x untyped, 2.0 never shipped).

### D4. No global state
No `static` state storage, no singletons, no global helper functions such as `app()`.
Everything that lives longer than a request sits in the container and implements `Resettable`
if it accumulates state. The request is passed explicitly as `ServerRequestInterface`. This is
a direct consequence of worker mode and the lesson of CVE-2026-24894.

### D5. One shared scope in the container, no request scope
Request-scoped services are a source of leaks in worker mode. Instead: everything that depends
on the request receives `ServerRequestInterface` as a handler argument or reads request
attributes. Services with a per-request cache implement `Resettable::reset()`; the kernel
calls it in `terminate()`.

### D6. Attributes + discovery with an explicit scope
Routes, middleware, commands and listeners are declared with attributes. Discovery scans only
the directories from `DiscoveryConfig::$paths` (`src/` by default), never `vendor/`. The result
is cached in `var/cache/discovery.php`; in dev, invalidation is by directory mtime. Packages
can register their own classes explicitly through a provider, without scanning.

### D7. Compilation into PHP files
`keel compile` (or the first request in prod) generates `var/cache/container.php`
(a class with factory methods, no reflection at runtime) and `var/cache/routes.php`
(a static array + regexes grouped by method, the FastRoute GroupCountBased approach).
In dev both are built on the fly.

### D8. Typed config
`config/*.php` files return objects (`final readonly class HttpConfig {...}`), not arrays.
The container registers them by class. Environment variables are read once at startup in
`Env`; services do not touch `$_ENV`/`getenv()` directly. YAML/XML are not supported.

### D9. Runtimes as adapters
`RuntimeInterface::run(Kernel): int`. Implementations: `FpmRuntime`, `FrankenPhpRuntime`
(a `frankenphp_handle_request` loop, `MAX_REQUESTS`, `gc_collect_cycles()` after a request,
`\Throwable` is caught inside the loop), `RoadRunnerRuntime` (via `spiral/roadrunner-http`,
an optional dependency), `ConsoleRuntime`. Auto-selection: `KEEL_RUNTIME`, otherwise by
`function_exists('frankenphp_handle_request')` and `RR_MODE`.

### D10. Errors — RFC 9457 Problem Details
A single `ExceptionHandlerMiddleware`: in prod — JSON `application/problem+json`, in dev —
HTML with a stack trace and a "copy as Markdown" button. `HttpException` exceptions carry
a status and headers.

### D11. Monorepo, one package at the start
`keel/framework` with `src/<Component>/`. Components do not depend on each other cyclically, so
that it can later be split into `keel/container`, `keel/router`, etc. via subtree split.

### D12. Quality as part of the core
PHPStan level max, PER-CS 3.x (php-cs-fixer), PHPUnit 12, Infection later. A separate
security test suite: method override, route placeholders with constraint bypass, state leaks
between requests in the worker loop. A benchmark harness against Slim 4 and Symfony
MicroKernel on FrankenPHP in `benchmarks/`.

## 3. Components

```
src/
  Container/   PSR-11 2.0, autowiring, attributes, lazy ghosts, compiler
  Http/        PSR-15 pipeline, handler argument resolver, responses, HttpException
  Routing/     Route/Get/Post/... attributes, Prefix, Middleware; compiler and matcher; UrlGenerator
  Kernel/      Kernel (boot/handle/terminate), ExceptionHandlerMiddleware, Problem Details
  Runtime/     RuntimeInterface, Fpm, FrankenPhp, RoadRunner, Console
  Discovery/   directory class scanner, cache, DiscoveryInterface for extensions
  Config/      Env, config/*.php loader, base config objects
  Event/       PSR-14 dispatcher, Listener attribute
  Console/     symfony/console integration, Command attribute (optional)
```

### Container
- `ContainerInterface` (PSR-11 2.0) + `Definitions` for providers:
  `$d->set(Foo::class)->factory(fn(Bar $b) => new Foo($b))->shared()`.
- Autowiring by constructor types; scalars — via `#[Inject('param.name')]` or a value from
  a config object.
- Attributes: `#[Shared]` (default), `#[Transient]`, `#[Lazy]` (→ `newLazyGhost`),
  `#[Alias(Interface::class)]`, `#[Tag('name')]` + `#[TaggedIterator('name')]`.
- `Resettable` interface; `Container::reset()` walks the initialized shared services.
- Compiler: generates a `final class CompiledContainer` with a method per service; lazy via
  `ReflectionClass::newLazyGhost` in the generated code.
- Errors: `NotFoundException`, `ContainerException` with the dependency chain in the message.

### Routing
- `#[Route('/users/{id:\d+}', methods: ['GET'], name: 'users.show')]` and the shortcuts
  `#[Get]`, `#[Post]`, `#[Put]`, `#[Patch]`, `#[Delete]`.
- `#[Prefix('/api/v1')]` and `#[Middleware(Auth::class)]` on class and method.
- Handler: a controller method (`[Class, method]`), an invokable class, a closure (dev/tests
  only, not compiled).
- Matcher: static routes via a hash, dynamic ones via regexes grouped by HTTP method. 405 with
  `Allow`, 404. HEAD → GET automatically. Method override only from `X-HTTP-Method-Override`
  and only when explicitly enabled in config (the lesson of Flight CVE-2026-42551).
- `UrlGenerator::generate('users.show', ['id' => 5])`.

### Http
- `Pipeline` — a PSR-15 `RequestHandlerInterface`, middleware FIFO in declaration order.
- `ArgumentResolver`: handler parameters are filled from route params (with coercion to
  int/float/bool/enum), `ServerRequestInterface`, container services, `#[FromQuery]`,
  `#[FromBody]` (JSON → readonly DTO via a simple mapper).
- Return: `ResponseInterface` as is; `array`/`JsonSerializable` → JSON 200;
  `string` → text/html; `null` → 204. `#[Status(201)]` on the method.
- `HttpException` and subclasses (`NotFound`, `MethodNotAllowed`, `BadRequest`, …).

### Kernel
- `Kernel::boot(string $projectDir, Env $env): self` — config, container, routes.
- `handle(ServerRequestInterface): ResponseInterface` — pipeline: global middleware →
  `RoutingMiddleware` → route middleware → handler.
- `terminate(ServerRequestInterface, ResponseInterface): void` — `Terminate` events,
  `Container::reset()`.
- Global middleware and providers — in `config/app.php` as an explicit list.

### Discovery
- Scans `.php` files in the given directories, determines the FQCN from namespace/class tokens
  (without `include`), and creates a `ReflectionClass` only for files with attributes from the
  list of interest (quick check by the `#[` text).
- The result is a `DiscoveryResult` (routes, middleware, commands, listeners, providers).
- Extension: `DiscoveryInterface { public function discover(\ReflectionClass $c): void; }`.

### Runtime
- `FrankenPhpRuntime`: `while (frankenphp_handle_request($handler)) { $kernel->terminate(); gc_collect_cycles(); if (++$n >= $max) break; }`.
- `FpmRuntime`: a single request, `ServerRequestCreator` from `nyholm/psr7-server`, an emitter
  with `headers_send(103)` support when available.

## 4. Usage example

```php
// public/index.php
return static fn (array $context): Kernel => Kernel::boot(dirname(__DIR__), Env::fromArray($context));
```

```php
// src/Http/UserController.php
#[Prefix('/api/users')]
#[Middleware(Auth::class)]
final class UserController
{
    public function __construct(private readonly UserRepository $users) {}

    #[Get('/{id:\d+}', name: 'users.show')]
    public function show(int $id): UserDto
    {
        return $this->users->find($id) ?? throw new NotFoundException();
    }

    #[Post('')]
    #[Status(201)]
    public function create(#[FromBody] CreateUser $cmd): UserDto
    {
        return $this->users->create($cmd);
    }
}
```

## 5. Roadmap

| Stage | Contents | Definition of done |
|---|---|---|
| M0 | repository, composer, CI (phpstan, cs, phpunit), documents | `composer test` is green on an empty core |
| M1 | Container + compiler | 100 % container coverage, benchmark of compiled vs reflection |
| M2 | Http + Routing + Kernel | hello-world and CRUD example under php-fpm, router security tests |
| M3 | Runtime: FrankenPHP worker, RoadRunner | example in Docker, cross-request leak test |
| M4 | Discovery, Config, Event, Console | discovery cache, `keel compile`, `keel routes` |
| M5 | Documentation, benchmarks against Slim 4 and Symfony MicroKernel, 0.1.0 release | README with numbers |

## 6. Accepted organizational decisions (2026-10-21)

1. Name `Keel`, vendor `keel`, namespace `Keel\`.
2. Hosting — GitHub, MIT license, public repository.
3. Documentation in two languages: `docs/ru/` and `docs/en/`, README in English with a
   Russian version `README.ru.md`. Both languages are kept up to date within the same change.
4. We write our own router from the start (section 3, Routing); `league/route` is not
   considered as a fallback.
