# HTTP and kernel

Namespaces `Keel\Http` and `Keel\Kernel`. PSR-7 messages come from `nyholm/psr7`; middleware is
PSR-15, factories are PSR-17. The kernel is one object per worker and holds no request state.

## Request pipeline

```
ExceptionHandlerMiddleware → global middleware → RoutingMiddleware
    → route middleware (class, then method) → controller → Responder
```

```php
$kernel = Kernel::build(
    $definitions,                         // Keel\Container\Definitions
    [UserController::class],              // attribute-annotated controllers
    [CorsMiddleware::class],              // global middleware, in order
    debug: $env === 'dev',
);
$response = $kernel->handle($request);    // ServerRequestInterface → ResponseInterface
$kernel->terminate($request, $response);  // after sending: ResponseSent, deferred tasks, Container::reset()
```

`Kernel::build()` registers `Psr17Factory` under every PSR-17 interface, `Responder`,
`ArgumentResolver` and `Router` in the container unless the application already did. With a
compiled container and a route cache use the constructor
`new Kernel($container, $router, $middleware, $debug)`; call `Kernel::registerDefaults()`
before compiling. `terminate()` dispatches `ResponseSent`, runs the `DeferredTasks` and resets
the services; see [Runtimes](runtime.md#lifecycle-events).

`Pipeline` is a PSR-15 handler: middleware run in declaration order (FIFO); class names are
resolved from the container on every request, so the container owns their lifetime: a shared
service is one instance for the whole worker (implement `Resettable` to drop request state), a
transient one is fresh for each request. A `[class, arguments]` pair (what `#[MiddlewareWith]`
records, also allowed in `HttpConfig`) is built once by the pipeline with the named arguments
and the rest autowired. A pipeline instance holds no request state and is reused across
requests.

## Handler arguments

`ArgumentResolver` fills the controller method parameters, for each parameter in this order:

1. type `ServerRequestInterface`, `RouteMatch`, `Route` or `Input`: the request, the routing
   result or the [request reader](#input);
2. `#[FromQuery]`: a DTO is hydrated from all query parameters; a scalar is read from the
   query parameter with the argument's name (missing without a default: 400);
3. `#[FromBody]`: JSON (`application/json`, `*+json`) or `getParsedBody()`; a DTO is hydrated,
   an `array` receives the body as is; malformed JSON: 400. On a scalar parameter (`string`,
   `int`, ...) the attribute is a configuration error: `LogicException` on the first request;
   `#[FromHeader('X-Request-Id')]` and `#[FromCookie]` (the cookie named like the parameter, or
   `#[FromCookie('theme')]`) fill a scalar the same way: cast to the type (400 on mismatch),
   the default or `null` when absent, 400 without either; `#[FromFile]` binds an upload, see
   [File storage](storage.md#uploads);
4. a route placeholder with the parameter's name, cast to the type (`int`, `float`, `bool`,
   `string`, enum); a mismatch: 400;
5. a service from the container by type (`has()`);
6. the default value, or `null` for a nullable type;
7. otherwise `LogicException` (a configuration error, answered with 500).

## Input

`Keel\Http\Input`, injected by type, is the convenient read over the PSR-7 request for the odd
parameter and for middleware; DTOs with `#[FromQuery]` and `#[FromBody]` stay the typed way in.

```php
#[Post('/search')]
public function search(Input $input): array
{
    $q = $input->string('q', '');             // query and body merged, body wins
    $page = $input->int('page', 1);           // cast like the hydrator; "abc" is 400
    $city = $input->get('address.city');      // dotted paths into nested data
    $tags = $input->array('tags', []);
    $token = $input->bearerToken();
    $ip = $input->ip();                       // from TrustedProxiesMiddleware, else REMOTE_ADDR
    // ...
}
```

`all()`, `get($key, $default)`, `has()`, `filled()` (not null, "" or []), `only()`, `except()`,
`query()`, `string()`/`int()`/`float()`/`bool()`/`array()` with a default for an absent key,
`param($name)` (a route placeholder), `header()`, `cookie()`, `file()`/`files()` (uploads),
`bearerToken()`, `ip()`, `method()`, `path()`, `isSecure()`, `isJson()` (JSON body),
`wantsJson()` (JSON first in `Accept`, or a JSON body without `Accept`), `isAjax()`, and
`request()` for everything else. The body is read on first use and only for methods other than
GET and HEAD; malformed JSON is 400.

## Hydrator

`Hydrator::hydrate(Dto::class, array $data)` calls the DTO constructor with named values:
it casts scalars (`'31'` → `31`, `'true'` → `true`), enums by value or case name, nested DTOs
from arrays, union types by trial, and applies defaults and `null`. Every problem is collected
and reported as one `BadRequestException` with an `errors` field:
`["email: field is required", "address.zip: must be a string"]`.

A DTO constructor may reject a combination of values that no single rule can express by throwing
`InvalidArgumentException` or `DomainException`: the message becomes one more error, prefixed with
the field path for a nested DTO (`range: end must not be before start`). Any other exception from a
constructor is a bug in the DTO and answers 500.

JSON bodies are decoded with PHP's default nesting limit of 512 levels; a deeper document is a 400
("Maximum stack depth exceeded"). The body *size* is not capped by the framework: set
`post_max_size`, `client_max_body_size` or the equivalent of your server in front of it.

Integers must fit in `int`: `"99999999999999999999"` or `1e20` is "must be an integer", not a
silently saturated value. Union members are tried in a fixed order, `int`, `float`, `bool`,
`string`, then classes and enums, whatever the declaration order (PHP itself reports `int|string`
as `string|int`): for `int|string` the value `"42"` becomes `42` and `"x"` stays a string.

## Pagination

`#[FromQuery] PageRequest $page` reads `?page=3&perPage=50` (defaults 1 and 20, `perPage` at most
100; out of range is a 400 like any other query DTO); `offset()` and `limit()` feed the query.
Return a `Page` and the response is the envelope
`{"items": [...], "page": 3, "perPage": 50, "total": 93, "pages": 2}`:

```php
/** @return Page<UserView> */
#[Get('/users')]
public function list(#[FromQuery] PageRequest $page): Page
{
    return Page::of($this->users->slice($page->offset(), $page->limit()), $page, $this->users->count());
}
```

`@return Page<UserView>` (or `#[Response(200, type: Page::class, items: UserView::class)]`) gives
the OpenAPI document the element type. Subclass `PageRequest` with your own constructor to change
the defaults or the upper bound.

## Responses

`Responder::respond($result, ?int $status)`:

| Handler return | Response |
|---|---|
| `ResponseInterface` | as is (status replaced when `#[Status]` is set) |
| `null` | 204 without body |
| `string` | `text/html; charset=utf-8` |
| `Keel\Http\Redirect` | 3xx with `Location`: `Redirect::to('/login')`, `Redirect::route('users.show', ['id' => 5], 303)`, see [Routing](routing.md#redirects) |
| `Keel\Http\EventStream` | `text/event-stream`, produced while it is sent, see [Streaming](#streaming-and-server-sent-events) |
| array, `JsonSerializable`, object | `application/json` with `JSON_UNESCAPED_UNICODE`, `JSON_UNESCAPED_SLASHES`, `JSON_PRESERVE_ZERO_FRACTION` |

## Streaming and Server-Sent Events

```php
#[Get('/orders/{id}/progress')]
public function progress(int $id): EventStream
{
    return new EventStream(function () use ($id) {
        foreach ($this->tracker->follow($id) as $step) {
            yield new SseEvent($step, event: 'progress', id: (string) $step->sequence);
        }
        yield new SseEvent('done', event: 'end');
    });
}

#[Get('/orders/export')]
public function export(Responder $responder): ResponseInterface
{
    return $responder->stream(function () {
        foreach ($this->orders->cursor() as $order) {
            yield json_encode($order) . "\n";
        }
    }, 'application/x-ndjson');
}
```

The body is an `IteratorStream`: nothing runs until the emitter reads it, and every chunk goes to
the client as soon as the generator yields it. `EventStream` items are `SseEvent` objects
(`data`, `event`, `id`, `retry`, `comment`), strings (sent as `data`) or anything JSON-encodable;
`SseEvent::comment('ping')` is the usual heartbeat that keeps proxies from closing an idle
connection. Event streams carry `Cache-Control: no-cache` and `X-Accel-Buffering: no`, so nginx
passes events through instead of buffering them.

- `SapiEmitter` flushes PHP's output buffers after every chunk and, once `connection_aborted()`
  reports the client gone, stops and closes the body, which ends the generator (its `finally`
  runs). Keep `zlib.output_compression` off and make sure the proxy does not buffer.
- `RoadRunnerRuntime` sends a non-seekable body as a RoadRunner stream, one frame per chunk
  (RoadRunner 2023.3 or newer); every other body still goes out in one frame.
- The status line and headers are sent before the generator runs: an exception it throws is
  logged by the runtime as critical and cuts the response short. Catch what you can inside.
- An open stream holds a worker (a php-fpm child, a FrankenPHP thread, a RoadRunner worker) until
  it ends. Size the pool for the streams you expect and keep capacity for ordinary requests with
  `LoadSheddingMiddleware` (`maxInFlight`, see [Runtimes](runtime.md#load-shedding)).
- `HttpCacheMiddleware`, `#[OutputCache]` and idempotency keys leave streamed bodies alone.

## Errors: RFC 9457 Problem Details

`ExceptionHandlerMiddleware` is the outermost middleware and turns every exception into
`application/problem+json`:

```json
{"type":"about:blank","title":"Not Found","status":404,"detail":"No route for GET /nope"}
```

- `Keel\Http\Exception\HttpException` and its subclasses (`BadRequest`, `Unauthorized`,
  `Forbidden`, `NotFound`, `MethodNotAllowed`, `Conflict`, `ContentTooLarge`,
  `UnsupportedMediaType`, `UnprocessableContent`, `TooManyRequests`, `ServiceUnavailable`) set the
  status, headers (such as `Allow`, `WWW-Authenticate`) and a client-safe message in `detail`;
  `errors` is added when present.
- Any other exception is a 500 with the title "Internal Server Error" and no `detail`; it is
  logged through the `LoggerInterface` from the container when one is registered.
- In `debug` mode `detail` and an `exception` object with class, file, line and trace are added.
- When the client prefers `text/html` over JSON per `Accept` (higher `q`, or listed first for an
  equal `q`) an HTML page is sent instead of JSON: status and title only in production; in debug
  the exception chain, the trace, the request with `Authorization`/`Cookie` redacted and a
  "Copy as Markdown" button.
- The body is encoded with `ProblemDetails::JSON_FLAGS` (`Responder::JSON_FLAGS` plus
  `JSON_INVALID_UTF8_SUBSTITUTE`), so a message with stray bytes cannot break the error response.
  Should rendering fail anyway (an `HttpException` with a header value PSR-7 refuses, for
  instance), the failure is logged as `critical` and a bare `{"status":500}` problem is sent.

## Validation

`Keel\Validation\*` attributes on DTO constructor parameters are checked by the hydrator after
type casting; their messages join the same `errors` list:

```php
final readonly class SignUp
{
    public function __construct(
        #[NotBlank, Length(min: 2, max: 50)] public string $name,
        #[Email] public string $email,
        #[Range(min: 18)] public int $age,
        #[Pattern('/^[a-z0-9-]+$/', 'must be a slug')] public string $slug = '',
        #[Choice(['free', 'pro'])] public string $plan = 'free',
        #[Count(max: 5)] public array $tags = [],
        #[Url(schemes: ['https'])] public ?string $site = null,
    ) {}
}
```

Rules: `NotBlank`, `Length`, `Range`, `Count`, `Pattern`, `Alnum(allow: '-_')`, `Email`, `Url`,
`Uuid`, `Ip(version: 4|6)`, `Hostname`, `Date(format: 'Y-m-d')`, `Json`, `Timezone`, `Choice`,
`Unique` (no duplicate items) and `Each(new NotBlank(), new Length(max: 30))`, which runs its
rules on every item of an array and reports the first failing one as `tags: [2]: must not be
blank`. A rule runs
only on fields that were sent; `null` is checked by `NotBlank` alone; after a type error the
field's rules are skipped. A custom rule is an attribute class implementing
`Keel\Validation\Rule::check()`.

## Built-in middleware

Listed in `HttpConfig`; their settings are plain config objects in `config/`:

| Middleware | Config | What it does |
|---|---|---|
| `CorsMiddleware` | `CorsConfig` | answers preflights (204) before routing, adds `Access-Control-*`; origins exact, `*` or patterns like `https://*.example.com`; `allowCredentials` requires an explicit origin list (`*` is refused in the constructor) |
| `RequestIdMiddleware` | — | trusts a well-formed `X-Request-Id` or issues one; `request_id` attribute and response header |
| `TrustedProxiesMiddleware` | `TrustedProxiesConfig` | applies `X-Forwarded-For/Proto/Host/Port` only from trusted CIDRs; client IP in the `client_ip` attribute |
| `HttpCacheMiddleware` | `HttpCacheConfig` | `Cache-Control` and `ETag` for 200 responses to GET/HEAD, 304 on `If-None-Match` / `If-Modified-Since`; see below |
| `CsrfMiddleware` | `CsrfConfig` | synchronizer token on top of the session, see [Sessions](session.md#csrf-protection) |
| `SecurityHeadersMiddleware` | `SecurityHeadersConfig` | HSTS (HTTPS only), CSP, `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, COOP and CORP; see below |
| `TrustedHostsMiddleware` | `TrustedHostsConfig` | 400 for a host outside the allowlist; see below |
| `BodyLimitMiddleware` | `BodyLimitConfig` | 413 above the size limit, 415 for a media type not listed; `#[BodyLimit]` per route |
| `ServerTimingMiddleware` | `ServerTimingConfig` | `Server-Timing` with the total and what handlers measured, optionally `X-Response-Time` |
| `IdempotencyMiddleware` | `IdempotencyConfig` | stored responses for a repeated `Idempotency-Key`; `#[Idempotent]` per route |
| `LoadSheddingMiddleware` | `LoadSheddingConfig` | 503 with `Retry-After` under overload, see [Runtimes](runtime.md#load-shedding) |
| `MethodOverrideMiddleware` | — | `POST` + `X-HTTP-Method-Override` → `PUT`/`PATCH`/`DELETE` |
| `AuthMiddleware` | `AuthConfig` | authenticates requests carrying credentials, see [Authentication](auth.md) |
| `HealthMiddleware` | `HealthConfig` | `/health` and `/health/live`, see [Health and metrics](observability.md) |
| `MetricsMiddleware` | `MetricsConfig` | Prometheus metrics and `/metrics` |
| `OpenApiMiddleware` | `OpenApiConfig` | `/openapi.json` and `/docs`, see [OpenAPI](openapi.md) |

Route attributes add their own middleware: `#[Deprecated]` adds `DeprecationMiddleware` (below),
`#[OutputCache]` adds `OutputCacheMiddleware` (see [Cache](cache.md#output-cache)).

`TrustedProxiesMiddleware` takes the rightmost `X-Forwarded-For` address that is not itself a
trusted proxy. When every hop is trusted, as with `proxies: ['*']`, it takes the rightmost valid
address, the one appended by the proxy next to us, never the leftmost entry, which is whatever the
client sent. A trusted `X-Forwarded-Proto` that changes the scheme also drops the port of the
listening socket unless `X-Forwarded-Port` or a port in `X-Forwarded-Host` says otherwise.

The order of the list matters. One that works for an API behind a proxy:

```php
return new HttpConfig([
    TrustedProxiesMiddleware::class,    // scheme, host and client IP as the client saw them
    HealthMiddleware::class,            // probes: any Host, never shed
    MetricsMiddleware::class,
    LoadSheddingMiddleware::class,
    TrustedHostsMiddleware::class,
    ServerTimingMiddleware::class,      // measures everything below
    OpenApiMiddleware::class,           // before the strict CSP, its HTML page loads a script
    SecurityHeadersMiddleware::class,
    CorsMiddleware::class,
    BodyLimitMiddleware::class,
    RequestIdMiddleware::class,
    AuthMiddleware::class,
    IdempotencyMiddleware::class,       // after auth: keys are per principal
]);
```

### HTTP caching

`HttpCacheMiddleware` acts on 200 responses to GET and HEAD. It adds the configured
`Cache-Control` when the handler set none, an `ETag` hashed from the body when the handler set
none (`etag: false` turns that off), and answers a request whose `If-None-Match` matches the
ETag (weak comparison, lists and `*` understood) or whose `If-Modified-Since` is not older than
the response's `Last-Modified` with an empty 304 that keeps the validators and caching headers.
The handler still runs: what is saved is the transfer, not the work.

```php
// config/http_cache.php: defaults for the global middleware
return new HttpCacheConfig(etag: true);

// per route or per controller
#[Get('/catalog')]
#[CacheControl(maxAge: 300, public: true)]            // public, max-age=300 + ETag
public function catalog(): array { /* ... */ }

#[Get('/me')]
#[CacheControl(noStore: true)]                        // no-store, no ETag
public function me(Principal $user): array { /* ... */ }
```

`#[CacheControl(maxAge, sharedMaxAge, public, mustRevalidate, immutable, noStore, etag)]` adds
the middleware to the route with its own settings, so it works without the global middleware
and overrides `HttpCacheConfig` when both are present. Global middleware runs before routing
and therefore sees only the config; the attribute is what makes settings per route. Hashing
reads the whole body, so leave `etag` off for large downloads and streams (a non-seekable body
is skipped anyway).

### Security headers

```php
// config/security_headers.php
return SecurityHeadersConfig::api();   // JSON only: CSP "default-src 'none'; frame-ancestors 'none'"

return new SecurityHeadersConfig(
    contentSecurityPolicy: "default-src 'self'; img-src 'self' data:",
    permissionsPolicy: ['camera' => [], 'geolocation' => ['self']],   // camera=(), geolocation=(self)
);
```

The defaults: `Strict-Transport-Security: max-age=31536000; includeSubDomains` on HTTPS requests
only (browsers ignore it over plain HTTP; behind a TLS-terminating proxy the scheme comes from
`TrustedProxiesMiddleware`), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
`Referrer-Policy: strict-origin-when-cross-origin` and `Cross-Origin-Opener-Policy: same-origin`.
CSP (optionally report-only), `Permissions-Policy` and `Cross-Origin-Resource-Policy` stay off
until configured; set `hstsPreload` only after submitting the domain to the preload list. A
header the response already has is kept, so one page (an HTML report, the API reference) can
send its own policy. `X-Powered-By` comes from PHP itself: set `expose_php = Off`.

### Trusted hosts

```php
// config/trusted_hosts.php
return new TrustedHostsConfig(['api.example.com', '*.example.com']);
```

A request for any other host gets 400 before routing, so a forged `Host` never ends up in
generated URLs, redirects or password-reset links. `*.example.com` covers subdomains at any depth
but not `example.com` itself; IP literals (`10.0.0.5`, `[::1]`) and `*` are allowed. The host is
read from the request URI, after `TrustedProxiesMiddleware` applied a trusted `X-Forwarded-Host`.
Probes that address a pod by its IP need that IP in the list, or `HealthMiddleware` earlier in
`HttpConfig`.

### Request body limits

```php
// config/body_limit.php: every request with a body
return new BodyLimitConfig(maxBytes: '1M', contentTypes: ['application/json', 'application/*+json']);

#[Post('/avatars')]
#[BodyLimit('20M', contentTypes: ['multipart/form-data', 'image/*'])]
public function upload(#[FromFile] UploadedFileInterface $avatar): array { /* ... */ }
```

A `Content-Length` above the limit is answered 413 before anything is read; a body without one
(chunked) is wrapped so that reading past the limit throws 413 instead of filling the worker's
memory. A body whose media type matches none of the patterns (exact, `type/*` or
`type/*+suffix`) gets 415. Requests without a body pass untouched. The global middleware reads
the route's `#[BodyLimit]` with a cheap match before routing, so a route can raise the limit
above the global one; a `null` argument keeps the global value. The attribute works without the
global middleware too, and the OpenAPI document lists 413 and 415 for the route. The server's
own limits apply first: `post_max_size` under php-fpm, `http.max_request_size` in RoadRunner,
`request_body` in Caddy.

### Idempotency keys

```php
// config/idempotency.php; needs a PSR-16 cache (CacheProvider)
return new IdempotencyConfig(ttl: 86_400);

#[Post('/payments')]
#[Idempotent(required: true)]
public function pay(#[FromBody] Payment $payment): array { /* ... */ }
```

The client sends `Idempotency-Key: <uuid>` with an unsafe request (draft-ietf-httpapi-idempotency-key-header).
The first request runs and its response (any status below 500 except 429) is stored for `ttl`
seconds. A retry with the same key and the same method, path, query and body gets that response,
marked `Idempotent-Replayed: true`, without running the handler; the same key with a different
request gets 422; a retry while the first is still running gets 409 with `Retry-After`. A request
that failed (5xx or an exception) releases its key, so the retry runs. Keys are 1-255 visible
ASCII characters (a quoted structured-field string is accepted) and are scoped to the principal,
or else to the `Authorization` header: place the middleware after `AuthMiddleware`.

The lock is an atomic `CounterStorage` increment: APCu, Redis (`RedisConfig(use: ['counters'])`)
or, without either, process memory, which only protects one worker. As global middleware it
covers POST and PATCH (`IdempotencyConfig::$methods`) that carry a key, merged with the route's
`#[Idempotent]`; the attribute alone applies to the route whatever its method and documents the
header, 409 and 422 in OpenAPI. Streamed responses are not stored.

### Server-Timing

```php
// config/server_timing.php
return new ServerTimingConfig(responseTimeHeader: 'X-Response-Time');

#[Get('/users/{id}')]
public function show(int $id, ServerTiming $timing): UserView
{
    $user = $timing->measure('db', fn() => $this->users->find($id), 'User lookup');
    $timing->add('cache', 0.4);

    return UserView::from($user);
}
```

`ServerTimingMiddleware` measures everything below it and answers
`Server-Timing: db;dur=3.2;desc="User lookup", cache;dur=0.4, total;dur=7.9`, which browsers show in
the network panel. `ServerTiming` is a fresh collector per request, stored as a request attribute
and injected by type (`ServerTiming::of($request)` in a middleware); `start()`/`stop()`,
`measure()` and `add()` record milliseconds. `timingAllowOrigin` lets pages of other origins read
the numbers, `metrics: false` sends the total alone. Timings tell anyone how long your backends
take: think twice before exposing them on a public API.

### Deprecating routes

```php
#[Get('/v1/users')]
#[Deprecated(since: '2026-07-01', sunset: '2027-01-01', link: 'https://docs.example.com/v2', successor: 'https://api.example.com/v2/users')]
public function list(): array { /* ... */ }
```

Responses of the route carry `Deprecation: @1782864000` (RFC 9745), `Sunset: Fri, 01 Jan 2027
00:00:00 GMT` (RFC 8594), `Link: <...>; rel="deprecation"` and `Link: <...>; rel="successor-version"`;
without `since` the route is announced as deprecated already (`@0`). The OpenAPI operation gets
`deprecated: true` and `x-sunset`, and `keel:routes` marks the handler. Dates are read as UTC; a
sunset earlier than `since` or a URL that cannot sit in a `Link` header fails when routes are
compiled. Errors thrown by the handler are answered without the headers. Not to be confused with
PHP's own `#[\Deprecated]`, which makes calling the method emit `E_USER_DEPRECATED`.

## Miscellaneous

- `MethodOverrideMiddleware` is opt-in; it overrides the method only for `POST` and only to
  `PUT`/`PATCH`/`DELETE` based on the `X-HTTP-Method-Override` header.
- `SapiEmitter` sends a response through the SAPI (php-fpm, built-in server); the front
  controller example is `examples/hello/public/index.php`. A non-seekable body is streamed,
  see above. `SapiEmitter::finish()` ends the response for the client while the script goes on
  (`fastcgi_finish_request()`, `frankenphp_finish_request()`); `FpmRuntime` calls it before
  `Kernel::terminate()`.
