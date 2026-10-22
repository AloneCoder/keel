# HTTP client

Namespace `Keel\HttpClient`. A PSR-18 client on ext-curl, wired as
`Psr\Http\Client\ClientInterface`, with requests built from the PSR-17 factories the kernel
already registers. Guzzle or symfony/http-client drop in by aliasing the interface instead.

```php
// config/http_client.php
return new HttpClientConfig(
    timeout: 10.0,            // whole request, seconds
    connectTimeout: 3.0,
    maxRedirects: 5,          // 0 disables following redirects
    verifyTls: true,
    userAgent: 'myapp/1.0',
    headers: ['Accept' => 'application/json'],   // unless the request sets the header
    proxy: null,              // "http://proxy:3128"
);

// config/services.php
return new ServicesConfig([HttpClientProvider::class]);
```

```php
final class GeoClient
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
    ) {}

    public function lookup(string $ip): array
    {
        $request = $this->requests->createRequest('GET', 'https://geo.example.com/' . rawurlencode($ip))
            ->withHeader('Authorization', 'Bearer ' . $this->token);
        $response = $this->http->sendRequest($request);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('geo lookup failed: ' . $response->getStatusCode());
        }

        return json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
```

## Behaviour

- Status codes are returned, never thrown, as PSR-18 requires. `NetworkException` (DNS,
  connection, TLS, timeout; `getCode()` is the curl error number) and `RequestException`
  (unsupported scheme) implement the PSR-18 exception interfaces.
- Redirects are followed up to `maxRedirects`, by the client itself rather than by curl, so they
  behave like a browser: 301, 302 and 303 turn a request with a body into a `GET` without one,
  307 and 308 repeat the request as sent, `Authorization`, `Proxy-Authorization` and `Cookie`
  are dropped when the target is another origin (scheme, host or port), relative `Location`
  values are resolved against the request URI, and only `http`/`https` targets are followed.
  The response is the last hop's; when the limit is reached, the last redirect response is
  returned as is.
- Response header names are matched case-insensitively (`Set-Cookie` and `set-cookie` are one
  header with every value kept), folded continuation lines are joined, and a response the PSR-7
  factory refuses is a `RequestException`.
- The body of `POST`, `PUT`, `PATCH` and `DELETE` requests is sent as is; `GET` and `HEAD`
  never send one. Responses are buffered in memory: for large downloads stream through
  your own curl call or a dedicated client.
- One curl handle per client instance is reused across requests of a worker, so keep-alive
  connections to the same host survive between requests. After a failure the handle is
  recreated.

## Retries and circuit breaker

Two PSR-18 decorators, enabled from the config and stacked over `CurlClient` by the provider
(retries outside, the breaker inside, so every attempt passes the breaker). They wrap any PSR-18
client, Guzzle included, when built by hand.

```php
// config/http_client.php
return new HttpClientConfig(
    timeout: 5.0,
    retry: new RetryConfig(
        maxAttempts: 3,       // the first attempt included
        delay: 0.1,           // before the first retry, then x multiplier
        maxDelay: 2.0,        // cap of one wait, Retry-After included
        multiplier: 2.0,
        jitter: true,         // wait between half and all of the delay
        statuses: [408, 429, 500, 502, 503, 504],
    ),
    circuitBreaker: new CircuitBreakerConfig(
        failures: 5,          // consecutive failures that open the circuit of a host
        openFor: 30,          // seconds of refusing calls, then one probe
        window: 60,           // failures are forgotten this long after the last one
        statuses: [500, 502, 503, 504],
        shared: true,         // state in APCu: the workers of a server open it together
    ),
);
```

`RetryingClient` repeats a request after a network failure or one of `statuses`:

- Only requests safe to repeat: `GET`, `HEAD`, `OPTIONS`, `PUT`, `DELETE`, `TRACE`, or any
  method with an `Idempotency-Key` header (pair it with `#[Idempotent]` on the server side). A
  body that cannot be rewound is never sent twice; a seekable one is rewound before each attempt.
- Waits `delay × multiplier^(n-1)`, capped at `maxDelay`, with jitter. A `Retry-After` (seconds
  or an HTTP date) replaces the backoff; a `Retry-After` above `maxDelay` returns the response at
  once, since the wait would hold the worker that serves a client.
- The result is the last attempt's response or exception. `RequestException` (the request itself
  is wrong) and `CircuitOpenException` are never retried. Each retry is logged at `info` without
  the query string.

Retries run inside the request that needs the answer: with `timeout: 5.0` and three attempts a
client can wait over 15 s. Keep `timeout`, `maxAttempts` and `maxDelay` small on the request path.

`CircuitBreakerClient` keeps one circuit per origin (scheme, host, port):

- **Closed**: calls go out; network failures and `statuses` are counted, any other response
  resets the count. 429 is not a failure by default: the host is up and asks to slow down.
- **Open** after `failures` in a row: calls fail at once with `CircuitOpenException` (a PSR-18
  `NetworkExceptionInterface`, with the request and `$circuit`), without touching the network.
  Opening is logged as a warning.
- **Half-open** after `openFor` seconds: the first call is a probe and the others are refused for
  another `openFor`; the probe's success closes the circuit, its failure opens it again.

With `shared: true` and APCu loaded, the state is shared by every php-fpm child or FrankenPHP
thread of the server, so the circuit opens after `failures` calls in total rather than in every
worker; concurrent failures are counted approximately. Without APCu each worker has its own
circuits. `CircuitBreaker` also works alone, for calls that are not HTTP:

```php
$breaker = new CircuitBreaker(new CircuitBreakerConfig(failures: 3), $cache);   // any PSR-16 store
if (!$breaker->allow('search')) {
    return $this->fallback();
}
try {
    $result = $this->search->query($q);
    $breaker->success('search');
} catch (\Throwable $e) {
    $breaker->failure('search');
    throw $e;
}
$breaker->state('search');   // CircuitState::Closed, Open or HalfOpen
```

## Worker mode

The client holds no request state; a failed request leaves nothing behind. Per-worker
connection reuse is the main reason to keep the client a shared service rather than creating
one per request.
