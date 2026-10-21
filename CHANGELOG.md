# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow SemVer.
Until 1.0.0 minor versions may contain breaking changes.

## [1.2.1] - 2026-10-21

### Added

- `keel:make:check` and `keel:make:authenticator` generators; `keel:routes` shows `#[Auth]` roles
  and `#[RateLimit]` settings.
- Mutation testing (Infection) now covers `Keel\Auth`, `Keel\RateLimit`, `Keel\Health` and
  `Keel\Metrics` in CI (MSI 90%); the security suites count towards coverage.

### Changed

- `HealthMiddleware` computes the overall status without an ordering helper; `ApiKeyAuthenticator`
  no longer trims the header (PSR-7 does); `InMemoryCounters` accepts a `ClockInterface`.

## [1.2.0] - 2026-10-21

Integrations: the libraries an application reaches for next, wired as providers with typed
configs and documented for worker mode. All optional, nothing new is loaded unless a provider
is listed in `ServicesConfig`.

### Added

- **Database** (`Keel\Database`): `PdoProvider` + `PdoConfig` (shared `\PDO` with exception
  mode, assoc fetches, native prepares) and `DbalProvider` + `DbalConfig` (`fromUrl()`,
  Doctrine DBAL 4), each with a readiness check tagged `keel.health` (`PdoCheck`, `DbalCheck`;
  the latter flags a transaction left open).
- **Templates** (`Keel\View`): `ViewRenderer`, `TwigProvider` + `TwigConfig`, `LatteProvider`
  + `LatteConfig`.
- **Cache** (`Keel\Cache`): PSR-16 `CacheProvider` + `CacheConfig` with `ApcuCache`,
  `FileCache` and `ArrayCache`; driver `auto` picks APCu when loaded, else files.
- **HTTP client** (`Keel\HttpClient`): PSR-18 `CurlClient` + `HttpClientConfig` (timeouts,
  redirects, TLS, default headers, proxy, per-worker handle reuse), `HttpClientProvider`,
  `NetworkException` and `RequestException`.
- `psr/simple-cache` and `psr/http-client` are now required (interfaces only); `doctrine/dbal`,
  `twig/twig`, `latte/latte`, `ext-apcu` and `ext-curl` are suggested.
- Documentation pages for database, templates, cache and HTTP client in English and Russian.

## [1.1.0] - 2026-10-21

What a production API needs on top of the core. Backward compatible with 1.0; route caches
written by 1.0 still load.

### Added

- **Auth** (`Keel\Auth`): `Principal`/`Identity`, `Authenticator` with `BearerTokenAuthenticator`,
  `ApiKeyAuthenticator` and `HmacAuthenticator` (signed requests with a replay window),
  `PrincipalResolver`/`HmacSecrets` for the application side, `AuthConfig`, optional global
  `AuthMiddleware`, and `#[Auth('role', ...)]` which adds `AuthGuard` to the route: 401 with
  `WWW-Authenticate`, 403 on missing roles. Handlers receive `Principal` by type.
- **Rate limiting** (`Keel\RateLimit`): `#[RateLimit(limit, window, by: 'ip'|'principal')]`
  adding `RateLimitGuard`, fixed windows, `RateLimit-*` headers, 429 with `Retry-After`,
  `CounterStorage` with APCu and in-memory implementations; `TooManyRequestsException`.
- **Health** (`Keel\Health`): `HealthCheck` services tagged `keel.health`, `HealthMiddleware`
  serving readiness (`/health`, 503 when a check is down) and liveness (`/health/live`).
- **Metrics** (`Keel\Metrics`): `Registry` with counters, gauges and histograms rendered in the
  Prometheus text format, `MetricsStorage` (APCu shared across workers, or in-memory),
  `MetricsMiddleware` recording `http_requests_total` and `http_request_duration_seconds` per
  route pattern and serving `/metrics`.
- **OpenAPI** (`Keel\OpenApi`): `Generator` deriving an OpenAPI 3.1 document from routes, DTOs,
  validation rules, `#[Status]`, return types, `#[Auth]` and `#[RateLimit]`; `#[Operation]`,
  `#[Response]`, `#[Field]`, `#[Hidden]` for what the code cannot say; `OpenApiMiddleware`
  serving the JSON and an HTML reference (Scalar); `keel:openapi [--output]`.
- **Routing**: `RouteOption` and `MiddlewareProvider` attribute interfaces, `Route::$options`
  and `Route::option()`, so extension attributes travel with the route through the cache.
- `keel:serve`: development server on PHP's built-in server or FrankenPHP (`--frankenphp`,
  `--worker`, `--watch` restarting workers on changes in `src/`, `config/`, `.env`).
- `HttpException::reasonPhrase()` knows the 2xx/3xx phrases.
- `SECURITY.md`, `CONTRIBUTING.md`, issue and pull request templates; `tests/Security` covers
  credential forgery, HMAC replay and rate-limit evasion.
- Documentation pages for authentication, OpenAPI and observability in English and Russian.

## [1.0.0] - 2026-10-21

The public API is frozen: see docs/en/api.md for what semantic versioning covers.

### Added

- `tests/Security` (group `security`): placeholder-constraint bypasses, method override limits,
  header injection, host poisoning, forged session cookies, information leaks in production,
  escaping and redaction on the debug page, JSON limits, state isolation between requests.
- Mutation testing with Infection on the container and router (`composer infection`, CI gate 80% MSI).
- Load test under FrankenPHP worker mode against Slim 4 (`benchmarks/frankenphp/run.sh`).
- Documentation site built with mkdocs-material in English and Russian: https://alonecoder.github.io/keel/

### Changed

- Internals are marked `@internal`: parsers, compilers, matchers, the reflection planner,
  `AppBuilder`, `ErrorPage`, generator base classes.

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
