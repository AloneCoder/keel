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
$kernel->terminate();                     // after the response is sent: Container::reset()
```

`Kernel::build()` registers `Psr17Factory` under every PSR-17 interface, `Responder`,
`ArgumentResolver` and `Router` in the container unless the application already did. With a
compiled container and a route cache use the constructor
`new Kernel($container, $router, $middleware, $debug)`; call `Kernel::registerDefaults()`
before compiling.

`Pipeline` is a PSR-15 handler: middleware run in declaration order (FIFO); class names are
resolved from the container on first use and cached. A pipeline instance holds no request
state and is reused across requests.

## Handler arguments

`ArgumentResolver` fills the controller method parameters, for each parameter in this order:

1. type `ServerRequestInterface`, `RouteMatch` or `Route`: the request or the routing result;
2. `#[FromQuery]`: a DTO is hydrated from all query parameters; a scalar is read from the
   query parameter with the argument's name (missing without a default: 400);
3. `#[FromBody]`: JSON (`application/json`, `*+json`) or `getParsedBody()`; a DTO is hydrated,
   an `array` receives the body as is; malformed JSON: 400;
4. a route placeholder with the parameter's name, cast to the type (`int`, `float`, `bool`,
   `string`, enum); a mismatch: 400;
5. a service from the container by type (`has()`);
6. the default value, or `null` for a nullable type;
7. otherwise `LogicException` (a configuration error, answered with 500).

## Hydrator

`Hydrator::hydrate(Dto::class, array $data)` calls the DTO constructor with named values:
it casts scalars (`'31'` → `31`, `'true'` → `true`), enums by value or case name, nested DTOs
from arrays, union types by trial, and applies defaults and `null`. Every problem is collected
and reported as one `BadRequestException` with an `errors` field:
`["email: field is required", "address.zip: must be a string"]`.

## Responses

`Responder::respond($result, ?int $status)`:

| Handler return | Response |
|---|---|
| `ResponseInterface` | as is (status replaced when `#[Status]` is set) |
| `null` | 204 without body |
| `string` | `text/html; charset=utf-8` |
| array, `JsonSerializable`, object | `application/json` with `JSON_UNESCAPED_UNICODE`, `JSON_UNESCAPED_SLASHES`, `JSON_PRESERVE_ZERO_FRACTION` |

## Errors: RFC 9457 Problem Details

`ExceptionHandlerMiddleware` is the outermost middleware and turns every exception into
`application/problem+json`:

```json
{"type":"about:blank","title":"Not Found","status":404,"detail":"No route for GET /nope"}
```

- `Keel\Http\Exception\HttpException` and its subclasses (`BadRequest`, `Unauthorized`,
  `Forbidden`, `NotFound`, `MethodNotAllowed`, `Conflict`, `UnprocessableContent`) set the
  status, headers (such as `Allow`, `WWW-Authenticate`) and a client-safe message in `detail`;
  `errors` is added when present.
- Any other exception is a 500 with the title "Internal Server Error" and no `detail`; it is
  logged through the `LoggerInterface` from the container when one is registered.
- In `debug` mode `detail` and an `exception` object with class, file, line and trace are added.

## Miscellaneous

- `MethodOverrideMiddleware` is opt-in; it overrides the method only for `POST` and only to
  `PUT`/`PATCH`/`DELETE` based on the `X-HTTP-Method-Override` header.
- `SapiEmitter` sends a response through the SAPI (php-fpm, built-in server); the front
  controller example is `examples/hello/public/index.php`.
