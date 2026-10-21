# Sessions and signed cookies

Namespace `Keel\Session`. The session lives in a request attribute, never in a service, so
nothing leaks between requests in worker mode. Enable it with `SessionMiddleware` in
`HttpConfig` and a `SessionConfig` in `config/session.php`.

```php
// config/session.php
return static fn(Env $env): SessionConfig => new SessionConfig(
    secret: $env->require('APP_SECRET'),   // at least 32 characters
    storage: 'cookie',                     // or 'file'
    ttl: 14 * 86400,
);

// controller: the session arrives by parameter type
#[Post('/login')]
public function login(#[FromBody] Login $cmd, Session $session): array
{
    $session->regenerate();                // new id, defeats session fixation
    $session->set('user_id', $id);
    return ['ok' => true];
}
```

| Storage | Where the data lives | When it fits |
|---|---|---|
| `cookie` (default) | in the cookie itself, HMAC-SHA256 signed, with an expiry | no server state, scales horizontally; the client can read the data (no secrets), 4 KB limit |
| `file` | `var/sessions/sess_<id>.json`, only a signed id in the cookie | data hidden from the client, more than 4 KB; needs a shared file system or one node |

A custom storage (Redis, ...) implements `SessionStorageInterface` (`read`, `write`, `destroy`) and
is passed as the second argument of `SessionMiddleware` through a definition in a provider.

`Session` API: `get`, `set`, `has`, `remove`, `pull` (flash), `all`, `clear`, `regenerate`,
`destroy`, `id`, `isNew`. The cookie is sent only when the session changed; a destroyed or
emptied session clears the cookie (`Max-Age=0`) and removes the storage entry. Cookie flags:
`HttpOnly`, `SameSite` from the config (`Lax` by default), `Secure` automatically on https.

`CookieSigner` works on its own: `sign($value)` → `payload.signature`, `verify($cookie)` → the
value or `null` on a bad signature. Good for "remember me" and signed links.

Attribute injection works for any object: a middleware stores an object in the request under
its class name (`$request->withAttribute(Identity::class, $identity)`) and a handler parameter
`Identity $identity` receives it without touching the container.
