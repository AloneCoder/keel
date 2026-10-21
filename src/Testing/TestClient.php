<?php

declare(strict_types=1);

namespace Keel\Testing;

use Keel\App\App;
use Keel\Kernel\Kernel;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Sends PSR-7 requests straight into the kernel, no SAPI, no sockets. Use it in PHPUnit:
 *
 *   $client = TestClient::forApp(App::boot(__DIR__ . '/..', new Env(['APP_ENV' => 'test'])));
 *   $client->json('POST', '/api/users', ['name' => 'Ann'])->assertStatus(201)->assertJson(['name' => 'Ann']);
 *
 * Every request ends with Kernel::terminate(), exactly like a worker does.
 */
final class TestClient
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var array<string, string> */
    private array $cookies = [];

    /** @var array<string, mixed> */
    private array $server = ['REMOTE_ADDR' => '127.0.0.1', 'SERVER_NAME' => 'localhost'];

    /**
     * @param (\Closure(): void)|null $terminate called after every request
     */
    public function __construct(
        private readonly RequestHandlerInterface $handler,
        private readonly ?\Closure $terminate = null,
    ) {}

    public static function forApp(App $app): self
    {
        return self::forKernel($app->kernel);
    }

    public static function forKernel(Kernel $kernel): self
    {
        return new self($kernel, $kernel->terminate(...));
    }

    /**
     * Default header for every following request (e.g. Authorization). Returns a new client.
     */
    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function withCookie(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->cookies[$name] = $value;

        return $clone;
    }

    /**
     * @param array<string, mixed> $server extra $_SERVER-style values (REMOTE_ADDR, HTTPS, ...)
     */
    public function withServer(array $server): self
    {
        $clone = clone $this;
        $clone->server = $server + $this->server;

        return $clone;
    }

    /**
     * @param array<string, string> $headers
     */
    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->request('GET', $uri, $headers);
    }

    /**
     * @param array<string, mixed>|string|null $body array = form fields, string = raw body
     * @param array<string, string>            $headers
     */
    public function post(string $uri, array|string|null $body = null, array $headers = []): TestResponse
    {
        return $this->request('POST', $uri, $headers, $body);
    }

    /**
     * @param array<string, mixed>|string|null $body
     * @param array<string, string>            $headers
     */
    public function put(string $uri, array|string|null $body = null, array $headers = []): TestResponse
    {
        return $this->request('PUT', $uri, $headers, $body);
    }

    /**
     * @param array<string, mixed>|string|null $body
     * @param array<string, string>            $headers
     */
    public function patch(string $uri, array|string|null $body = null, array $headers = []): TestResponse
    {
        return $this->request('PATCH', $uri, $headers, $body);
    }

    /**
     * @param array<string, string> $headers
     */
    public function delete(string $uri, array $headers = []): TestResponse
    {
        return $this->request('DELETE', $uri, $headers);
    }

    /**
     * Sends a JSON body with the matching Content-Type and Accept headers.
     *
     * @param array<string, mixed>|list<mixed> $data
     * @param array<string, string>            $headers
     */
    public function json(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $headers += ['Content-Type' => 'application/json', 'Accept' => 'application/json'];

        return $this->request($method, $uri, $headers, json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, string>            $headers
     * @param array<string, mixed>|string|null $body
     */
    public function request(string $method, string $uri, array $headers = [], array|string|null $body = null): TestResponse
    {
        $headers = $headers + $this->headers;
        $raw = is_string($body) ? $body : '';
        if (is_array($body)) {
            $headers += ['Content-Type' => 'application/x-www-form-urlencoded'];
            $raw = http_build_query($body, '', '&', \PHP_QUERY_RFC3986);
        }

        $psrUri = new Uri(str_starts_with($uri, '/') ? 'http://localhost' . $uri : $uri);
        $request = new ServerRequest(strtoupper($method), $psrUri, $headers, $raw, '1.1', $this->server);
        parse_str($psrUri->getQuery(), $query);
        $request = $request->withQueryParams($query)->withCookieParams($this->cookies);
        if (is_array($body)) {
            $request = $request->withParsedBody($body);
        }

        try {
            return new TestResponse($this->handler->handle($request));
        } finally {
            ($this->terminate)?->__invoke();
        }
    }
}
