# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow SemVer.
Until 1.0.0 minor versions may contain breaking changes.

## [Unreleased]

Hardening release after a full code review. No public method was removed or renamed; constructors
only gained optional trailing parameters. A few misconfigurations that used to be accepted silently
now throw at construction or compile time (listed under Changed).

### Added

- `keel:doctor`: a check-up of the installation (PHP, extensions, configuration, cache directory and
  files, runtime and worker limits, optional packages, a dry run of the container, routes, storage
  directories, health checks) with `OK`/`WARN`/`FAIL` lines and exit code 1 on a failure.
- `Container\Diagnostics`: resolves every registered service, alias and tag without instantiating
  anything and returns the problems; `ContainerException` carries `$dependency`, `$reason`,
  `$autowire` and `$chain` for a missing service and `report()` prints the diagnosis with the
  resolution path (shown by `keel:compile` and `keel:doctor`).
- `Runtime\MemoryGuard`: FrankenPHP and RoadRunner workers stop, with a logged warning, once the
  process holds more than `KEEL_MAX_MEMORY` (default 90% of `memory_limit`); `MetricsMiddleware`
  exports `process_memory_bytes`.
- `Http\Pagination\PageRequest` and `Page`: `?page=&perPage=` from the query string, the
  `{items, page, perPage, total, pages}` envelope, and its OpenAPI schema from `@return Page<Dto>`
  or `#[Response(type: Page::class, items: Dto::class)]`.
- `Telemetry\TracingMiddleware`: one OpenTelemetry server span per request (route-named, HTTP
  semantic attributes, W3C trace context propagation, errors recorded); needs `open-telemetry/api`
  and a `TracerProviderInterface` in the container.

### Security

- `ApcuCounters` fails closed: when APCu cannot store a counter the limit reads as exhausted
  instead of silently disabled.
- `TrustedProxiesMiddleware` with `proxies: ['*']` resolves an all-trusted `X-Forwarded-For`
  chain to the rightmost valid address; the client-supplied leftmost entry is never used.
- `CorsConfig` rejects `allowedOrigins: ['*']` together with `allowCredentials: true`.
- `HmacAuthenticator` takes an optional `CounterStorage` and rejects a byte-identical replay within
  the tolerance window; unknown key ids answer "Invalid signature" after a dummy HMAC (no enumeration);
  `sign()` validates the key id.
- `BearerTokenAuthenticator` answers 401 `invalid_request` for a present but malformed header instead
  of treating it as anonymous.
- `#[RateLimit(by: 'principal')]` authenticates by itself when no principal is attached yet, so it no
  longer degrades to a per-IP bucket depending on middleware order; counter keys include the method
  and the key kind.
- Sessions: `SessionConfig` validates `sameSite` and forces `Secure` for `None`; `FileStorage` refuses a
  symlinked directory, creates it `0700` and session files `0600`, and garbage-collects stale temp files;
  `CookieSigner` accepts a `$purpose` to separate signing contexts.
- `FileCache` refuses a symlinked or group/world-writable directory and sweeps stale temp files.
- `MetricsMiddleware` records only the standard HTTP methods as labels (`OTHER` for the rest), and the
  registry rejects the storage separator in label values, so clients cannot grow or corrupt `/metrics`.
- `/health` no longer exposes raw exception messages unless `HealthConfig::$exposeDetails` is set;
  exceptions are logged when a logger is wired.
- `StreamLogger` text format escapes CR/LF in messages (no log forging).
- `CurlClient` follows redirects itself, hop by hop: only http/https targets, 301/302/303 turn a
  non-GET request into GET without a body, `Authorization`/`Proxy-Authorization`/`Cookie` are dropped
  when the target is another origin; response headers are grouped case-insensitively, so multiple
  `Set-Cookie` headers survive.
- `/docs` pins the Scalar API reference script to a fixed version; the URL is configurable via
  `OpenApiConfig`.

### Fixed

- A `Definitions::instance()` registered by a provider broke every production boot after the first;
  `keel:compile` and the first non-debug boot now reject such instances with a message pointing to a
  static factory.
- An application definition under an interface id (`LoggerInterface`, PSR-17 factories,
  `ServerRequestCreatorInterface`, `ListenerProviderInterface`, `EventDispatcherInterface`) was shadowed
  by the framework's default alias; defaults now step aside, and `Definitions` rejects an alias that
  collides with a definition id.
- `PdoCheck` connects lazily inside `check()`, so an unreachable database is reported as `down` by
  `/health` instead of failing the middleware stack; it also reports a false `query()` result and an open
  transaction.
- `ExceptionHandlerMiddleware::render()` can no longer throw (invalid UTF-8 in an exception message);
  problem bodies are encoded with `JSON_INVALID_UTF8_SUBSTITUTE` and a minimal fallback response is
  returned when rendering fails.
- Route patterns containing capturing groups (named groups, `\(`) corrupted the chunk map and 500'd
  neighbouring routes; they are now rejected at compile time. `(` inside character classes is left alone.
- Both containers throw `ContainerException` (with the resolution chain) for a missing nested
  dependency, reserving `NotFoundException` for the requested id as PSR-11 requires.
- One throwing `Resettable::reset()` no longer aborts `Kernel::terminate()`; every service is reset and
  the failures are reported once.
- Runtime and compiled containers agree on lookup precedence (instances, services, parameters) and both
  reject alias loops; a nullable class-typed constructor parameter without default is optional.
- `App::boot()` refuses a `container.php` rewritten after its class was loaded in the same process.
- Discovery skips classes that cannot be loaded, scans subclasses that inherit attributed methods, and
  checks freshness with a stat-only pass; stale cache entries and unknown `#[Listener(event:)]` classes
  are rejected.
- `Env`: a quoted value followed by a comment keeps its content, not its quotes; `fromGlobals()` skips
  request variables (`HTTP_*`, `REQUEST_URI`, …) from `$_SERVER`.
- `ConfigLoader` reads directories whose path contains glob metacharacters and rejects two files
  returning the same config class.
- `Hydrator`: out-of-range integers are rejected instead of saturating; union types are tried as int,
  float, bool, string, then objects, regardless of declaration order.
- `UrlGenerator` validates the decoded value, so unicode placeholders generate; `Allow` lists `HEAD` when
  `GET` is allowed; `#[Prefix('/api')]` + `#[Get('/')]` yields `/api`; an empty URI path routes as `/`;
  `X-Forwarded-Proto` drops the listening port when the scheme changes.
- `ErrorPage::prefersHtml()` honours `Accept` q-values; `#[FromBody]` on a scalar parameter is a
  configuration error at plan time; route middleware pipelines are cached per handler.
- Session expiry is refreshed when less than half of the TTL is left (storages implementing the new
  `SessionExpiry` interface); `destroy()` after `regenerate()` removes the old entry too;
  `InMemoryCounters` prunes at most once per second.
- PSR-16 `getMultiple()`/`setMultiple()` keep numeric-string keys as strings.
- OpenAPI: unique `operationId`s for multi-method routes and same-named controllers; nullable enums
  accept `null`; `#[Response]` merges with the derived 2xx; `@return list<User>` resolves `use` imports.
- Prometheus histograms are emitted in bucket order with zeros for empty buckets and `+Inf`, `_count`,
  `_sum` last; bucket bounds keep their precision; gauge values are clamped instead of overflowing.
- `keel:preload` excludes the cache and `config/` directories; `keel:make:*` validate class names and
  escape interpolated strings; `keel:make:authenticator Authenticator` and `keel:make:check Check` are
  rejected instead of generating an empty scheme or label.
- `Compiler` escapes the service id in generated doc comments and validates the class name and namespace.
- `RateLimiter::hit()` rejects a limit or window below 1 (a zero window used to reach `intdiv`);
  `SessionConfig` rejects a non-positive `ttl`; `RouteCompiler` rejects a chunk size below 1.
- `FpmRuntime` calls `Kernel::terminate()` in a `finally`: Resettable services are reset even when
  creating the request or emitting the response throws, and the exception still reaches the SAPI.
- `Pipeline` no longer caches middleware resolved by class name: a transient middleware is a fresh
  instance per request, a shared one stays the container's single instance.
- `TrustedProxiesMiddleware` validates `X-Forwarded-Host` as a hostname (RFC 1123 labels), an IPv4
  address or a bracketed IPv6 literal with a port of 1-65535; `-a.test`, `a..b` and a port such as
  `99999` (which used to end in a 500 from the URI) are ignored as a whole.
- `Hydrator` reports an `InvalidArgumentException` or `DomainException` thrown by a DTO constructor
  as a 400 error entry (with the field path for nested DTOs) instead of a 500.
- `Compiler` refuses PHP reserved words (`match`, `class`, `int`, `__CLASS__`, …) as the compiled
  class name instead of writing a file that does not parse; both containers refuse a non-public
  factory method with the same message (reflection used to call it, the generated code could not).
- A compiled container built with `autowire: false` no longer resolves the classes the compiler
  autowired for it: like the runtime container it serves registered definitions only (optional
  dependencies get their default, required ones fail with the resolution chain). The generated class
  lists those ids in a new `AUTOWIRED` constant; files generated before keep their behaviour.

### Changed

- `Definitions::set()`/`alias()` throw on an id/alias collision; `CorsConfig`, `SessionConfig` and
  `AppBuilder` throw on the misconfigurations listed above; duplicate dynamic routes and capturing groups
  in route patterns are compile-time `RoutingException`s.
- `Rule` attributes no longer declare `TARGET_PROPERTY` (the hydrator never read property rules).
- `RateLimiter::hit()` with a limit or window below 1, `SessionConfig` with `ttl` below 1, `RouteCompiler`
  with a chunk size below 1 and a private or protected factory method throw where they used to be accepted;
  a middleware registered as transient is now really instantiated per request.
- `Keel::MIN_PHP_VERSION_ID` is deprecated (unused).

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
