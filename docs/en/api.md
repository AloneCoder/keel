# API stability

From 1.0.0 Keel follows semantic versioning. What the promise covers:

**Stable: everything public that is not marked `@internal`.** Classes, methods, attributes,
configuration objects, exceptions and the formats below. Changing them in a breaking way means
a new major version, with a deprecation in the previous minor where feasible.

| Area | Stable entry points |
|---|---|
| Container | `Definitions`, `Definition`, `Container`, `Compiler`, `ContainerInterface`, `Resettable`, `ServiceProvider`, `Ref`, the `Attribute\*` classes, the `Exception\*` classes |
| Routing | `Router`, `Route`, `RouteMatch`, `MatchResult`, `MatchStatus`, the `Attribute\*` classes, `Exception\RoutingException` |
| HTTP | `Pipeline`, `CallableHandler`, `Responder`, `ArgumentResolver`, `Hydrator`, `EmitterInterface`, `SapiEmitter`, the `Attribute\*`, `Exception\*` and `Middleware\*` classes; from 1.4 `ServerTiming`, `EventStream`, `SseEvent`, `Stream\IteratorStream` |
| Validation | `Rule` and every rule attribute |
| Kernel | `Kernel`, `ExceptionHandlerMiddleware`, `ProblemDetails`; from 1.4 `DeferredTasks` and the `Event\*` classes |
| Runtime | `RuntimeInterface`, `Runtime`, `FpmRuntime`, `FrankenPhpRuntime`, `RoadRunnerRuntime`, `MemoryGuard`; from 1.4 `LoadSheddingMiddleware`, `LoadSheddingConfig`, `InFlightCounter` and its implementations |
| App | `App` (except `@internal` members), `Config\*`, `Discovery\DiscoveryInterface`, `Discoverer` and the built-in discoveries, `Event\*`, `Log\*`, `Session\*`, `Testing\*`, `Console\ConsoleFactory` |
| Auth (1.1) | `Principal`, `Identity`, `Authenticator`, `PrincipalResolver`, `HmacSecrets`, `SecurityScheme`, the built-in authenticators, `Authentication`, `AuthMiddleware`, `AuthGuard`, `AuthConfig`, `Attribute\Auth` |
| RateLimit (1.1) | `Attribute\RateLimit`, `RateLimiter`, `RateLimitResult`, `RateLimitGuard`, `CounterStorage` and its implementations |
| Health, Metrics (1.1) | `HealthCheck`, `HealthResult`, `HealthStatus`, `HealthConfig`, `HealthMiddleware`; `Registry`, `MetricsStorage` and its implementations, `MetricsConfig`, `MetricsMiddleware` |
| OpenAPI (1.1) | the `Attribute\*` classes, `OpenApiConfig`, `Generator`, `SchemaBuilder`, `OpenApiMiddleware` |
| Database (1.2) | `PdoConfig`, `PdoProvider`, `PdoCheck`, `DbalConfig`, `DbalProvider`, `DbalCheck` |
| View (1.2) | `ViewRenderer`, `TwigConfig`, `TwigProvider`, `TwigRenderer`, `LatteConfig`, `LatteProvider`, `LatteRenderer` |
| Cache (1.2) | `CacheConfig`, `CacheProvider`, `ArrayCache`, `ApcuCache`, `FileCache`, `InvalidKeyException` |
| HttpClient (1.2) | `HttpClientConfig`, `HttpClientProvider`, `CurlClient`, `NetworkException`, `RequestException`; from 1.5 `RetryingClient`, `RetryConfig`, `CircuitBreakerClient`, `CircuitBreaker`, `CircuitBreakerConfig`, `CircuitState`, `CircuitOpenException` |
| JWT (1.4) | `Auth\Jwt\JwtAuthenticator`, `JwtConfig`, `JwtVerifier`, `Jwt`, `JwtKey`, `JwtException`, `KeySet` and its implementations |
| Idempotency (1.4) | `IdempotencyMiddleware`, `IdempotencyConfig`, `Attribute\Idempotent` |
| Output cache (1.4) | `Cache\Attribute\OutputCache`, `OutputCacheMiddleware`, `OutputCacheStore`, `OutputCacheConfig` |
| Signed URLs (1.5) | `Auth\UrlSigner`, `SignedUrlConfig`, `SignedUrlStatus`, `SignedUrlGuard`, `Auth\Attribute\Signed`, `Session\CookieSigner::signature()` |
| Mail (1.5) | `MailConfig`, `MailerProvider`, `Mailer`, `MemoryTransport`, `MemoryTransportFactory`, `MailerCheck` |

**Stable formats.** The Problem Details JSON shape, the `errors` message format `"field: message"`,
the `Set-Cookie` attributes of sessions, the console command names and arguments, the health
report shape, the metric names and labels of `MetricsMiddleware`, the HMAC signing scheme, the signed URL scheme (`expires` and `signature` query parameters).
The OpenAPI document is a derived artifact: a minor release may describe the API more precisely.

**Not covered.** Anything marked `@internal` (parsers, compilers, matchers, the reflection planner,
`AppBuilder`, `ErrorPage`, `DocTypes`, generator base classes), `AbstractCache` as a base class
for third-party drivers, the on-disk format of `FileCache` entries, the generated cache files (`container.php`,
`routes.php`, `discovery.php`, `preload.php`: rebuild them with `keel:compile` after every upgrade),
the HTML of the error page, log line wording, and benchmark scripts.

**PHP versions.** The minimum PHP version is raised only in a major release; new PHP versions are
added to CI as they are released.

**Deprecations** are announced in the changelog and carry `#[\Deprecated]` with the replacement.
