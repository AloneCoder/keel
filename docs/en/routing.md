# Routing

Namespace `Keel\Routing`. Routes are declared with attributes on controller methods,
compiled into match tables and cached as a PHP file.

## Declaration

```php
use Keel\Routing\Attribute\{Get, Post, Put, Delete, Route, Prefix, Middleware, Status};

#[Prefix('/api/users')]
#[Middleware(AuthMiddleware::class)]
final class UserController
{
    #[Get('/{id:\d+}', name: 'users.show')]
    public function show(int $id): UserDto { /* ... */ }

    #[Post('')]
    #[Status(201)]
    public function create(#[FromBody] CreateUser $command): UserDto { /* ... */ }

    #[Route('/export', methods: ['GET', 'POST'], name: 'users.export')]
    #[Middleware(RateLimit::class)]
    public function export(): ResponseInterface { /* ... */ }
}
```

- `#[Get]`, `#[Post]`, `#[Put]`, `#[Patch]`, `#[Delete]` are shortcuts for `#[Route]` with one
  method. The attributes are repeatable: one method can serve several paths.
- `#[Prefix]` on the class is prepended to every path in the class. An empty method path `''`
  yields exactly the prefix.
- `#[Middleware]` on the class applies to every route of the class and runs before the
  method-level middleware.
- `#[Status]` sets the status of the response that `Responder` builds from the return value.
- A handler is a public non-static method. Closures are not supported: they cannot be compiled
  into the cache.

### Extension attributes

Two interfaces let other attributes travel with a route. `RouteOption` stores a value on the
route (`Route::$options[key]`, read with `Route::option('key')`): declared on the class it applies
to every route, a method-level attribute with the same key overrides it; values go through the
route cache, so they must be exportable (scalars, arrays). `MiddlewareProvider` adds middleware
classes to the route; `#[Middleware]` itself is one. `#[Auth]` and `#[RateLimit]` implement both,
which is how they enforce themselves: the attribute records its settings and adds the guard that
reads them from the `RouteMatch` request attribute.

## Path syntax

- `{name}` matches one segment without `/` (`[^/]+`).
- `{name:regex}` uses a custom pattern, e.g. `{id:\d+}`, `{year:\d{4}}`, `{lang:(ru|en)}`.
  Capturing groups inside are turned into non-capturing ones.
- A path starts with `/`. The trailing slash is part of the path: `/users` and `/users/` are
  different routes.
- There are no optional segments: declare two routes on the same method.

## Matching

Static paths are looked up in a hash; dynamic ones with one `preg_match` per chunk of 10 routes
(the patterns are joined with `(?|...)` and the number of the matched group identifies the route,
as in FastRoute). Matching runs against the **raw** request path, without decoding, so that
`%2F` cannot change the structure; placeholder values are decoded individually with
`rawurldecode`. A `\d+` constraint is checked before decoding, so `/users/%34%32` does not match.

`Router::match($method, $path)` returns a `MatchResult` with status `Found` (a `RouteMatch`
holding the route and the parameters), `NotFound` or `MethodNotAllowed` with the `allowed`
list. `HEAD` without a route of its own is served by the `GET` route.

## Cache

```php
Router::fromControllers(UserController::class)->write('var/cache/routes.php');  // at build time
$router = Router::fromCache('var/cache/routes.php');                            // in production
```

The cache is a PHP array loaded with one `require` and kept in opcache. In development use
`Router::fromControllers()` on every request.

## URL generation

```php
$router->generate('users.show', ['id' => 5]);                   // /api/users/5
$router->generate('users.show', ['id' => 5, 'page' => 2]);      // /api/users/5?page=2
```

Extra parameters become the query string, `null` values are skipped, values are encoded with
`rawurlencode`. A missing parameter, a pattern violation and an unknown name raise
`RoutingException`.

## Configuration errors

`Keel\Routing\Exception\RoutingException` (a `LogicException`): a path without a leading `/`,
a placeholder name used twice, an invalid pattern, a route name declared twice, a static path
declared twice for the same method, a static or abstract handler.
