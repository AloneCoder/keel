# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow SemVer.
Until 1.0.0 minor versions may contain breaking changes.

## [0.3.0] - 2026-10-21

### Added

- `Keel\Session`: `Session` injected into handlers by type, `SessionConfig`, HMAC-signed cookies
  (`CookieSigner`), cookie storage (data in the signed cookie), file storage (signed id in the cookie),
  `ArrayStorage` for tests, `regenerate()` invalidating the previous id.
- `ArgumentResolver` injects request attributes keyed by the parameter's class.
- Console: `keel:make:controller`, `keel:make:command`, `keel:make:middleware`, `keel:make:listener`,
  `keel:debug:container`.
- Component packages split from the monorepo and replaced by `keel/framework`: `keel/container`,
  `keel/router`, `keel/http`, `keel/validation` (`bin/split`).

## [0.2.0] - 2026-10-21

Ready for a first real project: testing client, the middleware every API needs, validation,
logging, an HTML error page, a leaner production boot and `keel/skeleton`.

### Added

- `Keel\Testing\TestClient` and `TestResponse`: in-memory requests through the kernel with fluent
  PHPUnit assertions (status, headers, JSON subset and paths, Problem Details).
- `CorsMiddleware` + `CorsConfig`, `RequestIdMiddleware`, `TrustedProxiesMiddleware` + `TrustedProxiesConfig`.
- HTML error page for clients preferring `text/html`: exception chain, trace, redacted request and
  "Copy as Markdown" in debug mode; status and title in production.
- `Keel\Validation` rule attributes (`NotBlank`, `Length`, `Range`, `Count`, `Pattern`, `Email`,
  `Url`, `Choice`) checked by the hydrator and reported in Problem Details `errors`.
- `Keel\Log\StreamLogger` + `LoggerConfig`: dependency-free PSR-3 logger wired by `App::boot()`
  unless the application registers its own `LoggerInterface`.
- `keel:preload` writes an `opcache.preload` script from the files a request loads.
- `keel/skeleton` project template (`composer create-project keel/skeleton my-app`).

### Changed

- Production boot with warm caches no longer builds definitions or runs discovery; listeners are
  compiled into the container, `Route` objects are materialized on match, the compiled container's
  reflection fallback, the URL generator, the hydrator and the body parser are created on first use.
  Boot went from 58 to 30 loaded files and from 698 KB to 294 KB of heap without opcache.
- `App::controllers` is now a method, `App::controllers()`; the build side moved to `AppBuilder`.
- `ExceptionHandlerMiddleware` takes the container and resolves the logger on the first server error.

### Added

## [0.1.0] - 2026-10-21

First release: the whole core, PHP >= 8.4.

### Added

- **Container** (`Keel\Container`): PSR-11 2.0, constructor autowiring, `#[Inject]`,
  `#[TaggedIterator]`, `#[Lazy]` (PHP 8.4 lazy ghosts and proxies), `#[Transient]`,
  `#[Alias]`, `#[Tag]`, `Resettable` services, `Ref` argument overrides, static and
  instance method factories, a compiler generating one method per service with a
  reflection fallback, and a shared behaviour test suite for both implementations.
- **Routing** (`Keel\Routing`): `#[Route]`, `#[Get]`/`#[Post]`/`#[Put]`/`#[Patch]`/`#[Delete]`,
  `#[Prefix]`, `#[Middleware]`, `#[Status]`; path placeholders with constraints; group-count
  regex compiler; matcher with 405 + `Allow` and HEAD fallback; URL generator; PHP-array cache.
- **HTTP** (`Keel\Http`): PSR-15 pipeline, HTTP exceptions, `Responder`, argument resolver
  with `#[FromQuery]`/`#[FromBody]`, DTO hydrator with per-field errors, body parser,
  opt-in `MethodOverrideMiddleware`, SAPI emitter.
- **Kernel** (`Keel\Kernel`): routing and dispatch handlers, RFC 9457 Problem Details
  exception handler, `Kernel::build()` with PSR-17 defaults, `terminate()` reset.
- **Runtimes** (`Keel\Runtime`): php-fpm, FrankenPHP worker mode, RoadRunner; `Runtime::detect()`.
- **App** (`Keel\App`, `Keel\Config`, `Keel\Discovery`, `Keel\Event`, `Keel\Console`):
  `App::boot()` with `.env`, typed config objects, scoped attribute discovery with cache,
  PSR-14 events with `#[Listener]`, Symfony Console integration with `keel:*` commands.
- Documentation in Russian and English, hello example, in-process benchmarks.
