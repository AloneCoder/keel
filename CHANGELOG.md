# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow SemVer.
Until 1.0.0 minor versions may contain breaking changes.

## [Unreleased]

The second wave of the ecosystem survey: resilience for outgoing calls (Polly, SmallRye Fault
Tolerance, Hyperf, Fiber, tower), signed URLs (Laravel, Roda `hmac_paths`) and mail (Spiral,
Tempest, Leaf, Quarkus, Flask-Mail). No public method was removed or renamed.

### Added

- **HttpClient**: `RetryingClient` + `RetryConfig`: a PSR-18 decorator that repeats requests after
  a network failure or a transient status (408, 429, 500, 502, 503, 504) with exponential backoff
  and jitter; only idempotent methods or requests with an `Idempotency-Key`, never a body that
  cannot be rewound; `Retry-After` honoured up to `maxDelay`, a longer one returns the response.
- **HttpClient**: `CircuitBreakerClient` + `CircuitBreaker` + `CircuitBreakerConfig` +
  `CircuitState` + `CircuitOpenException`: one circuit per origin, opened after consecutive
  failures, a single probe when half-open, state in APCu shared by the workers of a server (or
  any PSR-16 store); `CircuitBreaker` also guards calls that are not HTTP.
  `HttpClientConfig::$retry` and `$circuitBreaker` stack both over `CurlClient` in the provider.
- **Auth**: signed URLs. `UrlSigner::route()` / `sign()` / `verify()` / `status()` +
  `SignedUrlConfig` (secret, default ttl, base URL for links in emails, purpose) +
  `#[Signed]` + `SignedUrlGuard` + `SignedUrlStatus`: path and query signed by `CookieSigner`
  under their own purpose, optional expiry, 403 for a missing, altered or expired signature;
  `expires` and `signature` appear as query parameters in OpenAPI. `keel:install signed-urls`.
- **Session**: `CookieSigner::signature()`: the detached signature of a payload.
- **Mail** (`Keel\Mail`): Symfony Mailer as a provider. `MailConfig` (DSN, default `From`,
  `Reply-To` and headers), `MailerProvider` (the transport from the DSN, Symfony's `Mailer` as
  `MailerInterface`, `MailerCheck` tagged `keel.health`), `Mailer` with `send()` and `defer()`
  (delivery through `DeferredTasks` after the response), `MemoryTransport` behind the
  `memory://default` DSN for tests. `keel:install mailer`; `symfony/mailer` is suggested and a
  dev dependency for the tests; `keel:doctor` lists it among the optional packages.

### Changed

- `HttpClientProvider` registers `ClientInterface` through a factory (`HttpClientProvider::create()`)
  instead of an alias of `CurlClient`; without retry or circuit breaker settings it still resolves
  to the `CurlClient` service.
- `OpenApi\Generator` documents `#[Signed]`.

## [1.4.0] - 2026-10-22

What the other ecosystems (Fastify, Hono, Express, Elysia, FastAPI, Starlette, Litestar, Flask,
Sanic, Gin, Echo, Fiber, Chi, Axum, Javalin, Quarkus, ASP.NET, Rails/Roda, Mezzio, Spiral,
Tempest, Leaf, Flight) ship first-party and Keel lacked: security headers, request limits, trusted
hosts, idempotency keys, Server-Timing, deprecation headers, streaming and Server-Sent Events,
load shedding, lifecycle events, work after the response, JWT/JWKS, a server-side output cache,
a component installer and AI-agent docs. No public method was removed or renamed; constructors
and `Kernel::terminate()` only gained optional trailing parameters.

### Added

- **HTTP**: `SecurityHeadersMiddleware` + `SecurityHeadersConfig` (HSTS on HTTPS requests only,
  CSP or CSP report-only, `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`
  from a feature map, COOP, CORP, extra headers; `SecurityHeadersConfig::api()` for JSON-only
  services); headers the response already has are kept.
- **HTTP**: `TrustedHostsMiddleware` + `TrustedHostsConfig`: 400 for a host outside the allowlist
  (exact names, `*.domain` at any depth, IP literals, `*`).
- **HTTP**: `BodyLimitMiddleware` + `BodyLimitConfig` + `#[BodyLimit('10M', contentTypes: [...])]`:
  413 for a `Content-Length` above the limit, a `LimitedStream` that throws 413 while reading a
  chunked body past it, 415 for a media type outside the patterns (`type/*`, `type/*+json`). The
  global middleware reads the route's attribute before routing, so a route can raise the limit.
- **HTTP**: `ServerTimingMiddleware` + `ServerTimingConfig` + `ServerTiming` (a per-request
  collector injected by type: `start()`/`stop()`, `measure()`, `add()`): `Server-Timing` with the
  handler's metrics and the total, optional `X-Response-Time` and `Timing-Allow-Origin`.
- **HTTP**: `#[Deprecated(since, sunset, link, successor)]` + `DeprecationMiddleware`:
  `Deprecation` (RFC 9745), `Sunset` (RFC 8594) and `Link` rel `deprecation` / `successor-version`;
  OpenAPI `deprecated: true` and `x-sunset`; `keel:routes` marks the handler.
- **HTTP**: streaming responses. `EventStream` as a handler return value (Server-Sent Events from
  `SseEvent` objects, strings or JSON values, `SseEvent::comment()` heartbeats),
  `Responder::stream()` and `Responder::eventStream()`, `Http\Stream\IteratorStream` (lazy,
  chunk by chunk, closing ends the generator). `SapiEmitter` flushes every chunk of a
  non-seekable body and stops when the client disconnects; `RoadRunnerRuntime` sends such bodies
  as RoadRunner stream frames.
- **HTTP**: `ContentTooLargeException` (413), `UnsupportedMediaTypeException` (415),
  `ServiceUnavailableException` (503); `SapiEmitter::finish()`.
- **Idempotency** (`Keel\Idempotency`): `IdempotencyMiddleware` + `IdempotencyConfig` +
  `#[Idempotent(required, ttl)]`: responses replayed for a repeated `Idempotency-Key` with
  `Idempotent-Replayed: true`, 422 for the key on a different request, 409 while the first runs
  (atomic lock in `CounterStorage`), keys released after a failure and scoped to the principal or
  the `Authorization` header; the header, 409 and 422 appear in OpenAPI.
- **Runtime**: `LoadSheddingMiddleware` + `LoadSheddingConfig`: 503 with `Retry-After` while too
  many requests are in flight across the workers sharing APCu (`InFlightCounter`,
  `ApcuInFlightCounter`, `LocalInFlightCounter`), a request queued too long in front of PHP
  (`X-Request-Start` / `X-Queue-Start`), the load average is too high or too little memory is
  left (cgroup v2, `MemAvailable`); probe paths exempt.
- **Kernel**: PSR-14 lifecycle events `RequestReceived`, `ExceptionRaised`, `ResponseSent`,
  `WorkerStarted`, `WorkerStopping` (`Keel\Kernel\Event`), built only when a listener applies;
  `Kernel::dispatch()`; `ListenerProvider::hasListenersFor()`.
- **Kernel**: `DeferredTasks::defer()`: closures run by `Kernel::terminate()` after the response
  was sent (php-fpm and FrankenPHP finish the request first), failures logged, registered by
  `Kernel::registerDefaults()`.
- **Auth**: `Jwt\JwtAuthenticator` + `JwtConfig` with `JwtVerifier`, `Jwt` (encode, parse),
  `JwtKey` (secrets, PEM, JWK), `StaticKeySet`, `JwksKeySet` (PSR-18 fetch, PSR-16 sharing,
  rate-limited refresh on an unknown `kid`, stale keys kept on failure) and `ChainKeySet`;
  HS/RS/ES 256/384/512 and EdDSA without a third-party library; algorithm allowlist, key-type
  checks against algorithm confusion, `crit` refused, `exp`/`nbf`/`iat` with leeway, issuer,
  audience and required claims; roles from a dotted claim path, scopes from `scope`/`scp`.
- **Cache**: `#[OutputCache(ttl, tags, varyByQuery, varyByHeaders, varyByPrincipal)]` +
  `OutputCacheMiddleware` + `OutputCacheStore::invalidateTags()` + `OutputCacheConfig`: whole
  responses served from PSR-16 with tag versions (works on any store, evicted versions read as
  stale), `X-Cache` and `Age`, placeholders in tags; authenticated requests are cached only per
  principal, never shared.
- **Console**: `keel:install <component>`: `composer require` for missing packages, providers
  appended to `config/services.php` and middleware to `config/http.php` (token-based edit that
  keeps comments and layout), starter config files; 21 components, dependencies between them,
  `--dry-run`, `--no-composer`.
- **Console**: `keel:ai:init`: `AGENTS.md` for coding agents built from the application (stack,
  commands, layout, worker-mode rules, middleware, providers, routes, the docs in `vendor/`) and
  `public/llms.txt` describing the HTTP API from the OpenAPI document.
- `RouteMatch::of($request, ?Router)`: the route match before or after routing, for global
  middleware that reads route options.

### Changed

- `Kernel::terminate(?ServerRequestInterface $request = null, ?ResponseInterface $response = null)`
  dispatches `ResponseSent` and runs the deferred tasks before `Container::reset()`. The
  runtimes and `TestClient` pass the request and the response that was sent.
- `FpmRuntime` with its default emitter finishes the request for the client
  (`fastcgi_finish_request()` and friends) before `Kernel::terminate()`.
- `FrankenPhpRuntime` calls `Kernel::terminate()` after `frankenphp_handle_request()` returned,
  once the response is complete, instead of inside the request handler.
- `ExceptionHandlerMiddleware` dispatches `RequestReceived` and `ExceptionRaised` when it has a
  container (new optional fourth constructor parameter).
- `OpenApi\Generator` takes an optional `IdempotencyConfig` and documents `#[Deprecated]`,
  `#[Idempotent]` and `#[BodyLimit]`.
- The `TestClient` terminate closure receives the request and the response.

### Fixed

- Docs: the built-in middleware table in `http.md` was cut in two by a paragraph.
- Tests: the Latte view test failed when it ran first in a random order (Infection), so the
  mutation workflow stopped before mutating anything; `keel:make:policy` gained its missing test
  and coverage metadata now credits code the suite already ran (lines 88.5% to 90.9%).
- `composer.json` branch alias moved from the stale `1.2.x-dev` to `1.4.x-dev`.

## [1.3.0] - 2026-10-22

Hardening after a full code review, operations tooling (`keel:doctor`, container diagnostics,
memory guard, tracing) and the features Slim and Lumen users reach for: optional path segments,
redirects, route groups, HTTP caching, CSRF, policies, middleware with arguments, a request
`Input` reader, log channels, Redis and Memcached, file storage and localization. No public
method was removed or renamed; constructors only gained optional trailing parameters. A few
misconfigurations that used to be accepted silently now throw at construction or compile time
(listed under Changed).

### Added

- **Routing**: `#[MiddlewareWith(Throttle::class, limit: 5)]` adds a middleware built with
  constructor arguments (named ones to the constructor, the rest autowired, once per route);
  route and global middleware lists accept `[class, arguments]` pairs; `keel:routes` shows them.
- **HTTP**: `Keel\Http\Input`, injected by type: query and body merged, dotted paths, typed
  readers cast like the hydrator (400 on mismatch), headers, cookies, uploads, bearer token,
  client IP, content negotiation. `#[FromHeader('X-Name')]` and `#[FromCookie]` bind scalars;
  `#[FromFile]` binds uploads (single, nullable or an array) with the `#[File]` rule (max size,
  sniffed MIME types, extensions) and a `multipart/form-data` request body in OpenAPI.
  `Hydrator` gains `int()`, `float()`, `bool()`, `string()` and `check()`.
- **Log**: named channels (`LoggerConfig::$channels` of `ChannelConfig`: stream, level, JSON or
  text, `rotate: 'daily'`, `syslog`) through `Loggers::channel()`; `LogContext` +
  `LogContextMiddleware` put `request_id`, `method`, `path`, `client_ip` and `principal` into
  every record of a request; `MonologProvider` drives Monolog from the same config.
- **Cache**: `Keel\Cache\Cache` with `remember()`, `RedisCache` (`driver: 'redis'`) and
  `MemcachedCache` (`driver: 'memcached'`, `CacheConfig::$servers`).
- **Redis** (`Keel\Redis`): `RedisConfig` (`fromUrl()`), `RedisProvider` registering the `\Redis`
  connection, `RedisCheck` for `/health`, and per `use` the Redis storages for sessions
  (`Session\RedisStorage`), rate-limit counters (`RateLimit\RedisCounters`) and metrics
  (`Metrics\RedisStorage`).
- **Storage** (`Keel\Storage`): `Storage` interface, `LocalStorage` (atomic writes, path
  hygiene), `FlysystemStorage`, `DiskConfig`/`StorageConfig`/`Disks`/`StorageProvider`.
- **I18n** (`Keel\I18n`): `Translator`/`ArrayTranslator` over PHP message files with
  placeholders, `|` plural forms by CLDR rules (ICU through ext-intl when present), domains and
  fallback; `LocaleMiddleware` (path prefix, query, session, cookie, `Accept-Language`); `Locale`
  by type; `I18nProvider`; `trans` filter and function for Twig and Latte.
- `monolog/monolog` and `league/flysystem` are suggested; both are dev dependencies for the tests.
- **Routing**: optional trailing segments in FastRoute syntax, `/users[/{id:\d+}[/{tab}]]`,
  compiled as several variants of one route; a short match leaves the placeholder out and the
  handler argument takes its default. `Router::generate()` picks the longest variant the given
  parameters satisfy; `Route::templates()` lists the variants; the OpenAPI document gets one
  path per variant.
- **Routing**: parent classes are route groups. `#[Prefix]`, `#[Middleware]` and `RouteOption`
  attributes on an abstract parent apply to every subclass: prefixes joined root-first, parent
  middleware first, options overridable below.
- **Routing**: `#[Redirect(from, to, status, methods, name)]` on a controller class declares a
  redirect route without a method, served by `Keel\Routing\Redirector` with the matched
  placeholders substituted into the target; `keel:routes` shows it as `redirect 308 -> /new`.
- **HTTP**: `Keel\Http\Redirect` as a handler return value (`Redirect::to()`, `Redirect::route()`)
  and `Responder::redirect()`; the `Responder` takes an optional `Router` for named redirects.
- **HTTP**: `HttpCacheMiddleware` + `HttpCacheConfig` (`Cache-Control`, body-hashed `ETag`, 304 on
  `If-None-Match` and `If-Modified-Since`) and `#[CacheControl]` for per-route settings.
- **Session**: `CsrfMiddleware`, `CsrfConfig` and `CsrfToken` (synchronizer token in the session,
  checked on unsafe methods from the `_token` field or the `X-CSRF-Token` header, exempt prefixes).
- **Auth**: policies. `Policy` (tagged `keel.policy`, declares abilities), `Gate` (`allows`,
  `denies`, `authorize`), `#[Can('ability')]` adding `PolicyGuard` with the route parameters as
  the subject, `keel:make:policy`; `keel:routes` and the OpenAPI document reflect `#[Can]`.
- **Validation**: `Uuid`, `Ip`, `Date`, `Alnum`, `Hostname`, `Json`, `Timezone`, `Unique` and
  `Each` (rules per array item), all mapped into the OpenAPI schema where JSON Schema has a word
  for them.
- `keel/router` now requires `psr/http-factory` (the `Redirector` builds responses).
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
