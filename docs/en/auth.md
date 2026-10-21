# Authentication and rate limiting

Namespaces `Keel\Auth` and `Keel\RateLimit`. Both work through route attributes that enforce
themselves: `#[Auth]` adds its guard middleware to the route, `#[RateLimit]` likewise, so a route
cannot be protected on paper and open at runtime. The OpenAPI generator reads the same attributes.

## Principal

Whoever a request was authenticated as implements `Principal` (`id()`, `roles()`, `hasRole()`).
`Identity` is the plain implementation: an id, roles and free-form attributes. Handlers receive
the principal by type, like the session:

```php
#[Get('/me')]
#[Auth]
public function me(Principal $user): array { return ['id' => $user->id()]; }

#[Get('/feed')]
public function feed(?Principal $user = null): array { /* personalised when signed in */ }
```

## Authenticators

An `Authenticator` recognises one credential scheme: it returns `null` when the request carries
no credentials of its kind, a `Principal` when they are valid, and throws
`UnauthorizedException` when they are present but wrong (a bad token is never anonymous).
Built in:

| Authenticator | Reads | Needs |
|---|---|---|
| `BearerTokenAuthenticator` | `Authorization: Bearer <token>` | a `PrincipalResolver` |
| `ApiKeyAuthenticator` | `X-Api-Key: <key>` (header configurable) | a `PrincipalResolver` |
| `HmacAuthenticator` | `Authorization: HMAC <keyId>:<timestamp>:<signature>` | `HmacSecrets` |

`PrincipalResolver::resolve(string $credential): ?Principal` and
`HmacSecrets::secretFor(string $keyId): ?string` are yours to implement (database, cache, a
static map); register them under the interface, with `#[Alias]` or in a provider:

```php
#[Alias(PrincipalResolver::class)]
final class ApiTokens implements PrincipalResolver
{
    public function __construct(private readonly TokenRepository $tokens) {}

    public function resolve(string $credential): ?Principal
    {
        $row = $this->tokens->find(hash('sha256', $credential));

        return $row === null || $row->expired() ? null : new Identity($row->userId, $row->roles);
    }
}
```

`config/auth.php` lists the authenticators to try, in order:

```php
return new AuthConfig([BearerTokenAuthenticator::class, HmacAuthenticator::class]);
```

Each is a container service, so its constructor is autowired; to change the realm, the header
name or the HMAC tolerance, define the service with arguments in a provider.

### HMAC request signing

For machine clients that should not hold a bearer token. The client signs
`"METHOD\n/path?query\n<unix timestamp>\n" . sha256(body)` with HMAC-SHA256 and sends
`Authorization: HMAC <keyId>:<timestamp>:<hex signature>`. The server accepts timestamps within
`$tolerance` seconds (300 by default), which bounds replays; a replay with a different body,
path or method fails because they are part of the signed string. Build the header with
`HmacAuthenticator::sign($keyId, $secret, $method, $target, $body, time())`; the resulting
principal is an `Identity` whose id is the key id.

## Requiring authentication

`#[Auth]` on a method or a class (every route of the controller) requires a principal; with
roles, one holding any of them:

```php
#[Delete('/users/{id}')]
#[Auth('admin', 'support')]
public function delete(int $id): void { /* ... */ }
```

The attribute adds `AuthGuard` to the route. The guard uses the principal `AuthMiddleware` left
on the request, or authenticates by itself when that middleware is not in the pipeline; without a
principal it answers 401 with a `WWW-Authenticate` header listing every scheme's challenge,
with a principal lacking the roles 403. Both are Problem Details responses.

`AuthMiddleware` is optional global middleware (`HttpConfig`): it authenticates every request
that carries credentials and attaches the principal, so public routes can personalise, and
rejects invalid credentials everywhere, not only on protected routes.

`Authentication` is the service behind both: `authenticate($request)` runs the configured
authenticators, `principal($request)` reads the attribute, `unauthorized()` builds the 401.

## Rate limiting

`#[RateLimit(limit, window, by)]` allows `limit` requests per `window` seconds (60 by default)
per client IP (`by: 'ip'`, the default) or per authenticated principal (`by: 'principal'`,
falling back to the IP for anonymous requests):

```php
#[Post('/search')]
#[RateLimit(30, 60)]
public function search(#[FromBody] Query $query): array { /* ... */ }
```

The attribute adds `RateLimitGuard`: every response carries `RateLimit-Limit`,
`RateLimit-Remaining` and `RateLimit-Reset` (seconds to the end of the window); once exhausted
the guard answers 429 with `Retry-After`. Windows are fixed and aligned to the clock
(`RateLimiter`); the client IP is what `TrustedProxiesMiddleware` established, so forwarded
headers only count from trusted proxies.

Counters live in a `CounterStorage`: APCu when the extension is loaded (shared by every worker of
the server), otherwise the memory of the current process. Register your own implementation
under `CounterStorage` for Redis or a database; `InMemoryCounters` suits tests.

## Testing

`TestClient` sends headers like any client: `$client->get('/me', ['Authorization' => 'Bearer t'])`
or `$client->withHeader('X-Api-Key', 'k')`. For HMAC use `HmacAuthenticator::sign()`. Register
`InMemoryCounters` as the `CounterStorage` in tests that exercise rate limits.
