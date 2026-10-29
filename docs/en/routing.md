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
  or `'/'` yields exactly the prefix (`/api`, not `/api/`). A prefix on a parent class comes
  first: parent classes are route groups, see below.
- `#[Middleware]` on the class applies to every route of the class and runs before the
  method-level middleware.
- `#[MiddlewareWith(Throttle::class, limit: 5, window: 60)]` adds one middleware built with
  constructor arguments, for middleware without an attribute of its own (third-party or
  generic). Named arguments go to the parameters of that name, the rest are autowired; the
  instance is built once per route and kept, so it must hold no request state. Values travel
  through the route cache, so they must be exportable. The same pair form, `[Throttle::class,
  ['limit' => 5]]`, works in the global middleware list of `HttpConfig`.
- `#[Redirect('/old', '/new', 308)]` on the class declares a route that only redirects; see
  [Redirects](#redirects).
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
- `[...]` at the end of the path marks optional segments, nested if needed:
  `/users[/{id:\d+}[/{tab}]]` serves `/users`, `/users/5` and `/users/5/posts`. A method path
  that is entirely optional (`#[Get('[/{path:.+}]')]`) attaches to the class prefix. A short
  match leaves its placeholders out of the parameters, so the handler argument takes its
  default (`?int $id = null`, `string $tab = 'profile'`); a parameter without a default is a
  configuration error. Brackets are only allowed at the end: `/a[/b]/c` and `/a[/b][/c]` are
  rejected. A `[` inside a constraint (`{slug:[a-z]+}`) is a character class, not a marker.

## Groups

A controller's parent classes act as route groups. `#[Prefix]`, `#[Middleware]` and every
`RouteOption` attribute (`#[Auth]`, `#[RateLimit]`, `#[CacheControl]`, ...) declared on a
parent apply to the subclass: prefixes are joined root-first, parent middleware runs first,
and an option declared again on the subclass or the method overrides the parent's.

```php
#[Prefix('/api/v1')]
#[Middleware(ApiKeyMiddleware::class)]
#[Auth]
abstract class ApiController {}

#[Prefix('/users')]                      // routes start with /api/v1/users
final class UserController extends ApiController
{
    #[Get('/{id}')]
    public function show(int $id): UserDto { /* ... */ }
}
```

The abstract parent is not a controller itself (discovery skips classes that cannot be
instantiated), so nothing is declared twice. Nest as deep as you like.

## Redirects

```php
#[Prefix('/api')]
#[Redirect('/v1/users/{id}', '/api/v2/users/{id}', 308, name: 'users.v1')]   // /api/v1/users/{id}
#[Redirect('/docs', 'https://docs.example.com/', methods: ['GET', 'POST'])]
final class UserController { /* ... */ }
```

`#[Redirect(from, to, status, methods, name)]` is repeatable on a controller class and needs
no method: the route is served by `Keel\Routing\Redirector`, which answers with the status
(302 by default; 301, 303, 307 or 308) and a `Location` built from `to`. The class prefix and
class middleware apply to `from` as to any route; `to` is used as written, a path or an
absolute http(s) URL, and may repeat the placeholders of `from`, whose matched values are
substituted percent-encoded. Redirect routes appear in `keel:routes` and are left out of the
OpenAPI document.

From a handler, return `Keel\Http\Redirect`: `Redirect::to('/login')`,
`Redirect::to('/done', 303)` or `Redirect::route('users.show', ['id' => 5])`, which the
`Responder` turns into the response (the named form needs the `Router`, which the kernel
injects). `Responder::redirect($location, $status)` builds the same response directly.

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
list. Methods are case-sensitive (RFC 9110): `post` is not `POST` and gets 405. `HEAD` without
a route of its own is served by the `GET` route (and counts against its `#[RateLimit]`), and
`allowed` (the `Allow` header of a 405) lists `HEAD` whenever it lists `GET`. An empty request path (PSR-7 allows it for
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
`rawurlencode`. With optional segments the longest variant whose placeholders are all given
(and not `null`) is generated: `generate('users')` is `/users`, `generate('users', ['id' => 5])`
is `/users/5`. Each value is checked against its placeholder constraint, either as given (with
`/u`, so `{slug:[\p{L}-]+}` accepts `wörld`) or in its encoded form (`a/b` satisfies `[^/]+` as
`a%2Fb`, exactly as the matcher will see it). A missing parameter, a pattern violation and an
unknown name raise `RoutingException`.

## Signed URLs

`Keel\Auth\UrlSigner` signs links that grant access by themselves: downloads, unsubscribe,
email confirmation. The path comes from the router, the signature from `CookieSigner` under
its own purpose, and `#[Signed]` checks it on the way in.

```php
// config/signed_url.php
return static fn(Env $env): SignedUrlConfig => new SignedUrlConfig(
    secret: $env->require('APP_SECRET'),       // 32+ characters
    ttl: 86_400,                               // default expiry in seconds; null = never
    baseUrl: 'https://app.example.com',        // for links that leave the app, as in emails
);
```

```php
#[Get('/downloads/{id:\d+}', name: 'downloads.show')]
#[Signed]                                       // 403 without a valid, unexpired signature
public function download(int $id): Response { /* ... */ }

$signer->route('downloads.show', ['id' => 5, 'format' => 'pdf'], expires: 3600);
// https://app.example.com/downloads/5?format=pdf&expires=1767225600&signature=Xb3...
$signer->sign('/reports/q3?inline=1', new \DateTimeImmutable('+1 day'));   // any path
$signer->verify($url);                          // true / false
$signer->status($request->getUri());            // SignedUrlStatus::Valid, Missing, Invalid, Expired
```

- The signature covers the path and the whole query string exactly as sent, `expires`
  included: a parameter added, removed, reordered or changed, or a later expiry, gives 403
  `Invalid URL signature`; a past one `This link has expired`; none `This URL must be signed`.
- Scheme, host and `baseUrl` are not signed: the link is valid wherever the application receives
  the same path. Behind a proxy that mounts the application under a prefix, put the prefix in
  `baseUrl` (`https://example.com/app`) and the links stay valid.
- `expires` is seconds from now or a `DateTimeInterface`; `signature` and `expires` are reserved
  query parameter names. `purpose` (default `signed-url`) keeps these signatures apart from
  session cookies signed with the same secret.
- A link is valid until it expires or the secret changes. To revoke single links, put something
  revocable in them (a token, a version) and check it in the handler.
- OpenAPI lists `expires` and `signature` as query parameters of `#[Signed]` operations, with a
  403 response.

## Configuration errors

`Keel\Routing\Exception\RoutingException` (a `LogicException`): a path without a leading `/`,
a placeholder name used twice, an invalid pattern or one with a capturing group that cannot be
neutralised, a route name declared twice, a static path or a dynamic pattern declared twice for
the same method (`/u/{id}` and `/u/{slug}` compile to the same regex), a static or abstract
handler, optional segments that are not at the end of the path, a `#[Redirect]` with a
non-3xx status or a target that is neither a path nor an http(s) URL.
