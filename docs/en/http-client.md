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
- Redirects are followed up to `maxRedirects`; the response is the last hop's.
- The body of `POST`, `PUT`, `PATCH` and `DELETE` requests is sent as is; `GET` and `HEAD`
  never send one. Responses are buffered in memory: for large downloads stream through
  your own curl call or a dedicated client.
- One curl handle per client instance is reused across requests of a worker, so keep-alive
  connections to the same host survive between requests. After a failure the handle is
  recreated.

## Worker mode

The client holds no request state; a failed request leaves nothing behind. Per-worker
connection reuse is the main reason to keep the client a shared service rather than creating
one per request.
