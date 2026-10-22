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
| `Jwt\JwtAuthenticator` | `Authorization: Bearer <JWT>` | `JwtConfig`: a secret, public keys or a JWKS URL |

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
principal is an `Identity` whose id is the key id. `sign()` refuses a key id that cannot travel
in the header (empty, over 128 bytes, containing a colon or whitespace).

An *identical* request replayed inside the tolerance window is accepted unless the authenticator
has a `CounterStorage` (its fourth constructor argument, autowired when one is registered, which
is the case as soon as you configure rate limiting): every signature is then accepted once and a
repeat answers 401 "Replayed signature". A client that retries after a lost response must sign
again with a fresh timestamp.

A wrong signature and an unknown key id get the same answer, "Invalid signature", after the same
amount of work, so key ids cannot be enumerated. Host and `Content-Type` are not part of the
signed string: a signature is valid on every host that shares the secret, so never share HMAC
secrets between environments.

### JWT

`JwtAuthenticator` verifies JSON Web Tokens without a third-party library: HS256/384/512,
RS256/384/512, ES256/384/512 and EdDSA (Ed25519); RSA-PSS is not supported.

```php
// config/jwt.php: tokens of an identity provider (Auth0, Keycloak, Cognito, Entra ID, ...)
return static fn(Env $env): JwtConfig => new JwtConfig(
    algorithms: ['RS256'],
    jwksUrl: 'https://login.example.com/.well-known/jwks.json',
    issuer: 'https://login.example.com/',
    audience: 'orders-api',
    rolesClaim: 'realm_access.roles',
);

// tokens you issue yourself
return static fn(Env $env): JwtConfig => new JwtConfig(algorithms: ['HS256'], secret: $env->require('JWT_SECRET'), issuer: 'orders-api');
$token = Jwt::encode(['sub' => $user->id, 'roles' => $user->roles, 'exp' => time() + 900, 'iss' => 'orders-api'], $secret);

// config/auth.php
return new AuthConfig([JwtAuthenticator::class]);
```

A token is accepted when its `alg` is one of `algorithms`, a key of the matching type verifies
the signature (with a `kid` only that key; an RSA key never verifies HS256, which defeats the
algorithm-confusion attack; `none` is never accepted), it has no `crit` header, `exp` is in the
future and `nbf`/`iat` not too far ahead (with `leeway`, 60 seconds by default), and `iss`,
`aud` and `requiredClaims` match the config. Tokens without `exp` are refused unless
`requireExpiration: false`. HMAC secrets must be at least 32 bytes, RSA keys 2048 bits.

The principal is an `Identity`: the id from `subjectClaim` (`sub`), the roles from `rolesClaim`
(a dotted path; a list or a space-separated string), what `#[Auth('admin')]` checks, and every
claim as an attribute, plus `scopes` as a list from `scope` or `scp`:
`$user->get('scopes')`, `$user->get('tenant')`. A bad token is answered 401 with
`WWW-Authenticate: Bearer realm="api", error="invalid_token"`; the reason (expired, wrong
audience, unknown key id) is logged at info level, never told to the client.

With `jwksUrl` the keys are fetched through the PSR-18 client (load `HttpClientProvider`), kept
in the worker for `jwksTtl` seconds (3600) and shared with the other workers through the PSR-16
cache when one is registered. A token with an unknown `kid` triggers a refetch, at most once a
minute, so a rotation is picked up without letting forged key ids hammer the provider. When a
refetch fails the known keys stay in use; without any, the request fails with a 500, not a 401.
`publicKeys` (PEM, keyed by `kid`) and `secret` can be combined with `jwksUrl`.
`$authenticator->verifier()->verify($token)` checks a token outside a request and returns its
claims. `keel:install jwt` writes `config/jwt.php` and `config/auth.php`.

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

## Policies

Roles answer "who are you"; policies answer "may you do this to that". A policy is a tagged
service deciding named abilities for a principal and a subject:

```php
#[Tag(Policy::TAG)]
final class PostPolicy implements Policy
{
    public function abilities(): array
    {
        return ['post.view', 'post.update', 'post.delete'];
    }

    public function allows(Principal $principal, string $ability, mixed $subject): bool
    {
        return match ($ability) {
            'post.view' => true,
            'post.update' => $subject instanceof Post && $subject->authorId === $principal->id(),
            'post.delete' => $principal->hasRole('admin'),
            default => false,
        };
    }
}
```

`keel:make:policy Post post.update post.delete` generates one. Discovery registers it through
the tag; `Gate` collects every tagged policy and asks the one declaring the ability:
`allows($user, 'post.update', $post)`, `denies(...)`, `authorize(...)` (throws 403
`ForbiddenException` on refusal) and `abilities()`. An ability no policy declares is a
`LogicException`, a configuration error rather than a silent denial; two policies declaring the
same ability are rejected when the gate is built.

In a handler, inject the `Gate` and the principal and check once the entity is loaded:

```php
#[Patch('/posts/{id}')]
#[Auth]
public function update(int $id, Principal $user, Gate $gate, #[FromBody] UpdatePost $cmd): PostDto
{
    $post = $this->posts->get($id);
    $gate->authorize($user, 'post.update', $post);
    // ...
}
```

For a decision the route parameters suffice for, `#[Can('post.update')]` on the method or the
class adds `PolicyGuard`: it authenticates like `AuthGuard` (401 without a principal) and asks
the gate with the route parameters as the subject (`['id' => '42']`), answering 403 on refusal.
`keel:routes` shows the ability in the Auth column; the OpenAPI document gets `security` plus 401
and 403, as with roles.

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
`RateLimit-Remaining` and `RateLimit-Reset` (seconds to the end of the window; the header names
follow the earlier IETF draft, not the newer single structured `RateLimit` field); once
exhausted the guard answers 429 with `Retry-After`. Windows are fixed and aligned to the clock
(`RateLimiter`), so up to twice the limit can pass around a window boundary; the client IP is
what `TrustedProxiesMiddleware` established, so forwarded headers only count from trusted proxies.

Counters are keyed by HTTP method, route pattern and subject (`GET|/search|ip:203.0.113.5`,
`GET|/mine|principal:u1`), so two methods on one path and an IP that looks like a principal id
never share a bucket. With `by: 'principal'` the guard authenticates the request itself when no
principal was attached yet (no `AuthMiddleware`, or `#[Auth]` declared after `#[RateLimit]`):
a request with credentials is always counted per principal, invalid credentials get 401, and
only a truly anonymous request falls back to the IP.

Counters live in a `CounterStorage`: APCu when the extension is loaded (shared by every worker of
the server), otherwise the memory of the current process. `RedisCounters` puts them on Redis
for the whole fleet (`RedisConfig(use: ['counters'])`, see [Cache](cache.md#redis)); register
your own implementation under `CounterStorage` for anything else; `InMemoryCounters` suits tests. `ApcuCounters`
fails closed: when APCu cannot store a counter (shared memory exhausted) the limit reads as
exceeded rather than silently disabled, so size `apc.shm_size` for your key count.

## Testing

`TestClient` sends headers like any client: `$client->get('/me', ['Authorization' => 'Bearer t'])`
or `$client->withHeader('X-Api-Key', 'k')`. For HMAC use `HmacAuthenticator::sign()`. Register
`InMemoryCounters` as the `CounterStorage` in tests that exercise rate limits.
