# Sessions and signed cookies

Namespace `Keel\Session`. The session lives in a request attribute, never in a service, so
nothing leaks between requests in worker mode. Enable it with `SessionMiddleware` in
`HttpConfig` and a `SessionConfig` in `config/session.php`.

```php
// config/session.php
return static fn(Env $env): SessionConfig => new SessionConfig(
    secret: $env->require('APP_SECRET'),   // at least 32 characters
    storage: 'cookie',                     // or 'file'
    ttl: 14 * 86400,                 // seconds of inactivity, at least 1
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
| `file` | `<filePath>/sess_<id>.json`, only a signed id in the cookie | data hidden from the client, more than 4 KB; needs a shared file system or one node |

`Keel\Session\RedisStorage` keeps sessions in Redis: `RedisConfig(use: ['sessions'])` with
`RedisProvider`, see [Cache](cache.md#redis). Any other storage implements
`SessionStorageInterface` (`read`, `write`, `destroy`) and is registered under that interface.
Implement `SessionExpiry` (`expiresAt`) as well and the middleware can refresh ageing sessions
(below).

**Cookie storage is signed, not revocable.** `destroy()` clears the browser's cookie, but a copy
of it stays valid until its expiry, and the client may send back any older cookie it kept, so
state stored there can be rolled back (a consumed flash, an attempt counter). Keep authoritative
state, and sessions you must be able to end from the server, in `file` or your own storage. Under
cookie storage `Session::id()` is a fresh random value on every request and `regenerate()`
changes nothing, so do not bind CSRF tokens or locks to the id there.

**File storage** writes 0600 files into a 0700 directory and refuses a directory that is a
symbolic link. Set `filePath` to a directory of your own (`var/sessions`): the default,
`<system temp dir>/keel-sessions`, is shared with every local user of the machine. Concurrent
requests of one session are not locked against each other; the last write wins.

`Session` API: `get`, `set`, `has`, `remove`, `pull` (flash), `all`, `clear`, `regenerate`,
`destroy`, `id`, `isNew`. The cookie is sent when the session changed, and also when less than
half of `ttl` is left, so `ttl` is a true inactivity timeout: an active user who never writes to
the session stays signed in. A destroyed or emptied session clears the cookie (`Max-Age=0`) and
removes the storage entry, including the one before a `regenerate()` in the same request.

Cookie flags: `HttpOnly`, `SameSite` from the config (`Lax`, `Strict` or `None`; anything else is
rejected), `Secure` automatically on https. `SameSite=None` always sends `Secure` and refuses
`secure: false`. "On https" is judged from the request URI: behind a TLS-terminating proxy that is
only right with `TrustedProxiesMiddleware` configured, so set `secure: true` explicitly otherwise.

`CookieSigner` works on its own: `sign($value)` → `payload.signature`, `verify($cookie)` → the
value or `null` on a bad signature; `signature($payload)` is the signature alone, for a payload
that travels separately. Good for "remember me"; signed links have their own
[`UrlSigner`](routing.md#signed-urls) built on it. Give every use its
own purpose, `new CookieSigner($secret, 'remember-me')`: a value signed for one purpose does not
verify under another, so a session id can never pass as a remember-me token. The default (empty)
purpose is what the session middleware uses and what earlier versions signed with.

Attribute injection works for any object: a middleware stores an object in the request under
its class name (`$request->withAttribute(Identity::class, $identity)`) and a handler parameter
`Identity $identity` receives it without touching the container.

## CSRF protection

`CsrfMiddleware`, listed in `HttpConfig` after `SessionMiddleware`, keeps a synchronizer token
in the session (created on first sight, kept for the life of the session) and leaves a
`CsrfToken` on the request. A request with an unsafe method (anything but GET, HEAD, OPTIONS,
TRACE) must repeat the token in the `_token` form field or the `X-CSRF-Token` header, or is
answered 403 `Invalid CSRF token`.

```php
// config/csrf.php (optional)
return new CsrfConfig(field: '_token', header: 'X-CSRF-Token', except: ['/api/']);

#[Get('/profile')]
public function form(CsrfToken $csrf): string
{
    return '<form method="post">' . $csrf->field() . '...</form>';   // hidden input
}
```

`CsrfToken::$value` goes into a `fetch()` header; `field()` renders the hidden input;
`CsrfMiddleware::token($request)` reads it from middleware. `except` lists path prefixes left
unchecked: an API that authenticates by bearer token or API key is not reachable with a
browser's ambient cookies and needs no token. To rotate the token (after login, say) remove the
session key (`_csrf` by default) and the next request gets a fresh one. Under cookie storage the
token travels inside the signed cookie, which is fine: the attacker's page cannot read it, and
that is all the check relies on.
