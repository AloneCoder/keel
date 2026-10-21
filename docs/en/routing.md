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
  or `'/'` yields exactly the prefix (`/api`, not `/api/`).
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
  Plain capturing groups inside are turned into non-capturing ones (`\(` and `[(]` stay literal);
  a named group such as `(?<n>...)` cannot be neutralised and is rejected with `RoutingException`.
- The pattern is matched against the **percent-encoded** path (see Matching): `\d+` and
  `[a-z-]+` work, `\p{L}` or any other non-ASCII constraint never matches an encoded `%C3%B6`.
  For non-ASCII values keep the default `[^/]+` and validate the decoded value in the handler.
- A path starts with `/`. The trailing slash is part of the path: `/users` and `/users/` are
  different routes.
- There are no optional segments: declare two routes on the same method.

## Matching

Static paths are looked up in a hash; dynamic ones with one `preg_match` per chunk of 10 routes
(the patterns are joined with `(?|...)` and the number of the matched group identifies the route,
as in FastRoute). Matching runs against the **raw** request path, without decoding, so that
`%2F` cannot change the structure; placeholder values are decoded individually with
`rawurldecode`. A `\d+` constraint is checked before decoding, so `/users/%34%32` does not match.

A static path always wins over a dynamic one. Among the dynamic routes of a method the **first
declared** route that matches wins, so when two patterns overlap (`/items/{id:\d+}` and
`/items/{slug}` both match `/items/42`) declare the more specific one first. Declaration order is
the order of the attributed methods in a controller, of the controllers passed to
`fromControllers()`, or of the list given to `fromRoutes()`; the route cache keeps it.

`Router::match($method, $path)` returns a `MatchResult` with status `Found` (a `RouteMatch`
holding the route and the parameters), `NotFound` or `MethodNotAllowed` with the `allowed`
list. `HEAD` without a route of its own is served by the `GET` route, and `allowed` (the `Allow`
header of a 405) lists `HEAD` whenever it lists `GET`. An empty request path (PSR-7 allows it for
`http://host`) is routed as `/`.

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
`rawurlencode`. Each value is checked against its placeholder constraint, either as given (with
`/u`, so `{slug:[\p{L}-]+}` accepts `wörld`) or in its encoded form (`a/b` satisfies `[^/]+` as
`a%2Fb`, exactly as the matcher will see it). A missing parameter, a pattern violation and an
unknown name raise `RoutingException`.

## Configuration errors

`Keel\Routing\Exception\RoutingException` (a `LogicException`): a path without a leading `/`,
a placeholder name used twice, an invalid pattern or one with a capturing group that cannot be
neutralised, a route name declared twice, a static path or a dynamic pattern declared twice for
the same method (`/u/{id}` and `/u/{slug}` compile to the same regex), a static or abstract
handler.
