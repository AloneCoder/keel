<?php

declare(strict_types=1);

namespace Keel\Tests\Http;

use Keel\Http\CallableHandler;
use Keel\Http\Middleware\CorsConfig;
use Keel\Http\Middleware\CorsMiddleware;
use Keel\Http\Middleware\RequestIdMiddleware;
use Keel\Http\Middleware\TrustedProxiesConfig;
use Keel\Http\Middleware\TrustedProxiesMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(CorsMiddleware::class)]
#[CoversClass(CorsConfig::class)]
#[CoversClass(RequestIdMiddleware::class)]
#[CoversClass(TrustedProxiesMiddleware::class)]
#[CoversClass(TrustedProxiesConfig::class)]
final class MiddlewareSetTest extends TestCase
{
    private ?ServerRequestInterface $seen = null;

    private function handler(): CallableHandler
    {
        return new CallableHandler(function (ServerRequestInterface $request): ResponseInterface {
            $this->seen = $request;

            return new Psr17Factory()->createResponse()->withHeader('Vary', 'Accept');
        });
    }

    public function testCorsPreflightAndSimpleRequests(): void
    {
        $cors = new CorsMiddleware(new Psr17Factory(), new CorsConfig(
            allowedOrigins: ['https://app.example.com', 'https://*.preview.example.com'],
            allowedHeaders: ['*'],
            exposedHeaders: ['X-Total'],
            allowCredentials: true,
            maxAge: 60,
        ));

        $preflight = $cors->process(new ServerRequest('OPTIONS', '/api', [
            'Origin' => 'https://app.example.com',
            'Access-Control-Request-Method' => 'PUT',
            'Access-Control-Request-Headers' => 'content-type, x-token',
        ]), $this->handler());
        self::assertSame(204, $preflight->getStatusCode());
        self::assertNull($this->seen, 'preflight never reaches the handler');
        self::assertSame('https://app.example.com', $preflight->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('content-type, x-token', $preflight->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('GET, POST, PUT, PATCH, DELETE, OPTIONS', $preflight->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('60', $preflight->getHeaderLine('Access-Control-Max-Age'));
        self::assertSame('true', $preflight->getHeaderLine('Access-Control-Allow-Credentials'));

        $simple = $cors->process(new ServerRequest('GET', '/api', ['Origin' => 'https://x.preview.example.com']), $this->handler());
        self::assertNotNull($this->seen);
        self::assertSame('https://x.preview.example.com', $simple->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('X-Total', $simple->getHeaderLine('Access-Control-Expose-Headers'));
        self::assertSame(['Accept', 'Origin'], $simple->getHeader('Vary'));

        $denied = $cors->process(new ServerRequest('OPTIONS', '/api', ['Origin' => 'https://evil.test', 'Access-Control-Request-Method' => 'GET']), $this->handler());
        self::assertSame(403, $denied->getStatusCode());

        $deniedSimple = $cors->process(new ServerRequest('GET', '/api', ['Origin' => 'https://evil.test']), $this->handler());
        self::assertFalse($deniedSimple->hasHeader('Access-Control-Allow-Origin'));

        $noOrigin = $cors->process(new ServerRequest('GET', '/api'), $this->handler());
        self::assertFalse($noOrigin->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testCorsWildcardWithoutCredentialsSendsStar(): void
    {
        $cors = new CorsMiddleware(new Psr17Factory());

        $response = $cors->process(new ServerRequest('GET', '/', ['Origin' => 'https://any.test']), $this->handler());

        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
    }

    public function testRequestIdIsTrustedWhenWellFormedOtherwiseGenerated(): void
    {
        $middleware = new RequestIdMiddleware();

        $response = $middleware->process(new ServerRequest('GET', '/', ['X-Request-Id' => 'abc-123.x']), $this->handler());
        self::assertSame('abc-123.x', $response->getHeaderLine('X-Request-Id'));
        self::assertNotNull($this->seen);
        self::assertSame('abc-123.x', RequestIdMiddleware::of($this->seen));

        $response = $middleware->process(new ServerRequest('GET', '/', ['X-Request-Id' => 'bad value!']), $this->handler());
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $response->getHeaderLine('X-Request-Id'));

        $untrusting = new RequestIdMiddleware(trustIncoming: false);
        $response = $untrusting->process(new ServerRequest('GET', '/', ['X-Request-Id' => 'abc']), $this->handler());
        self::assertNotSame('abc', $response->getHeaderLine('X-Request-Id'));
    }

    public function testTrustedProxiesRewriteClientIpAndUri(): void
    {
        $middleware = new TrustedProxiesMiddleware(new TrustedProxiesConfig(['10.0.0.0/8', '192.168.1.1', 'fd00::/8']));
        $headers = ['X-Forwarded-For' => '203.0.113.9, 10.1.2.3', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'api.example.com', 'X-Forwarded-Port' => '8443'];

        $middleware->process(new ServerRequest('GET', 'http://internal/x', $headers, null, '1.1', ['REMOTE_ADDR' => '10.9.9.9']), $this->handler());
        self::assertNotNull($this->seen);
        self::assertSame('203.0.113.9', TrustedProxiesMiddleware::clientIp($this->seen));
        self::assertSame('https://api.example.com:8443/x', (string) $this->seen->getUri());

        $middleware->process(new ServerRequest('GET', 'http://internal/x', $headers, null, '1.1', ['REMOTE_ADDR' => '198.51.100.1']), $this->handler());
        self::assertSame('198.51.100.1', TrustedProxiesMiddleware::clientIp($this->seen), 'untrusted peer: headers ignored');
        self::assertSame('http://internal/x', (string) $this->seen->getUri());

        $middleware->process(new ServerRequest('GET', '/x', ['X-Forwarded-For' => 'garbage, 10.0.0.1'], null, '1.1', ['REMOTE_ADDR' => 'fd00::1']), $this->handler());
        self::assertSame('10.0.0.1', TrustedProxiesMiddleware::clientIp($this->seen), 'no untrusted client in the chain: the rightmost valid hop, never the client-supplied left end');

        $middleware->process(new ServerRequest('GET', '/x', ['X-Forwarded-For' => 'garbage'], null, '1.1', ['REMOTE_ADDR' => 'fd00::1']), $this->handler());
        self::assertSame('fd00::1', TrustedProxiesMiddleware::clientIp($this->seen), 'nothing valid in the chain: REMOTE_ADDR');
    }

    public function testTrustAnyProxyNeverTakesTheClientSuppliedLeftmostAddress(): void
    {
        $middleware = new TrustedProxiesMiddleware(new TrustedProxiesConfig(['*']));

        // The client sent "X-Forwarded-For: 1.2.3.4"; the proxy appended the real peer address.
        $middleware->process(new ServerRequest('GET', '/x', ['X-Forwarded-For' => '1.2.3.4, 203.0.113.9'], null, '1.1', ['REMOTE_ADDR' => '10.0.0.1']), $this->handler());
        self::assertNotNull($this->seen);
        self::assertSame('203.0.113.9', TrustedProxiesMiddleware::clientIp($this->seen));

        $middleware->process(new ServerRequest('GET', '/x', ['X-Forwarded-For' => '198.51.100.7'], null, '1.1', ['REMOTE_ADDR' => '10.0.0.1']), $this->handler());
        self::assertSame('198.51.100.7', TrustedProxiesMiddleware::clientIp($this->seen), 'a single hop is the client');
    }

    public function testForwardedProtoAloneDropsTheInternalPort(): void
    {
        $middleware = new TrustedProxiesMiddleware(new TrustedProxiesConfig(['10.0.0.0/8']));

        $middleware->process(new ServerRequest('GET', 'http://internal:8080/x', ['X-Forwarded-Proto' => 'https'], null, '1.1', ['REMOTE_ADDR' => '10.0.0.1']), $this->handler());
        self::assertNotNull($this->seen);
        self::assertSame('https://internal/x', (string) $this->seen->getUri(), 'TLS was terminated upstream: 8080 is not the client-facing port');

        $middleware->process(new ServerRequest('GET', 'http://internal:8080/x', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '8443'], null, '1.1', ['REMOTE_ADDR' => '10.0.0.1']), $this->handler());
        self::assertSame('https://internal:8443/x', (string) $this->seen->getUri(), 'an explicit forwarded port wins');

        $middleware->process(new ServerRequest('GET', 'http://internal:8080/x', ['X-Forwarded-Proto' => 'http'], null, '1.1', ['REMOTE_ADDR' => '10.0.0.1']), $this->handler());
        self::assertSame('http://internal:8080/x', (string) $this->seen->getUri(), 'same scheme: nothing changes');
    }

    public function testCorsRefusesWildcardOriginWithCredentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('allowCredentials');
        new CorsConfig(allowedOrigins: ['https://app.test', '*'], allowCredentials: true);
    }

    public function testCidrMatching(): void
    {
        self::assertTrue(TrustedProxiesConfig::matches('10.255.0.1', '10.0.0.0/8'));
        self::assertFalse(TrustedProxiesConfig::matches('11.0.0.1', '10.0.0.0/8'));
        self::assertTrue(TrustedProxiesConfig::matches('192.168.1.130', '192.168.1.128/25'));
        self::assertFalse(TrustedProxiesConfig::matches('192.168.1.127', '192.168.1.128/25'));
        self::assertTrue(TrustedProxiesConfig::matches('2001:db8::1', '2001:db8::/32'));
        self::assertFalse(TrustedProxiesConfig::matches('2001:db9::1', '2001:db8::/32'));
        self::assertFalse(TrustedProxiesConfig::matches('10.0.0.1', '2001:db8::/32'), 'family mismatch');
        self::assertFalse(TrustedProxiesConfig::matches('not-an-ip', '10.0.0.0/8'));
        self::assertTrue(new TrustedProxiesConfig(['*'])->trusts('8.8.8.8'));
    }


    public function testForwardedHostMustBeAHostWithAnOptionalValidPort(): void
    {
        $middleware = new TrustedProxiesMiddleware(new TrustedProxiesConfig(['10.0.0.0/8']));
        $server = ['REMOTE_ADDR' => '10.0.0.1'];

        $ignored = ['-example.com', 'example..com', 'exa_mple.com', 'example.com:0', 'example.com:99999', 'example.com:', 'evil.test/path', 'a:b:c', '::1', '[::1', '[not-an-ip]:80', str_repeat('a', 64) . '.test'];
        foreach ($ignored as $host) {
            $middleware->process(new ServerRequest('GET', 'http://internal:8080/x', ['X-Forwarded-Host' => $host], null, '1.1', $server), $this->handler());
            self::assertNotNull($this->seen);
            self::assertSame('http://internal:8080/x', (string) $this->seen->getUri(), 'ignored as a whole: ' . $host);
        }

        $accepted = [
            'API.Example.com' => 'http://api.example.com/x',
            'api.example.com:8443' => 'http://api.example.com:8443/x',
            '127.0.0.1:65535' => 'http://127.0.0.1:65535/x',
            '[2001:DB8::1]:8443' => 'http://[2001:db8::1]:8443/x',
            '[::1]' => 'http://[::1]/x',
            'localhost' => 'http://localhost/x',
            'xn--80ak6aa92e.com' => 'http://xn--80ak6aa92e.com/x',
        ];
        foreach ($accepted as $host => $uri) {
            $middleware->process(new ServerRequest('GET', 'http://internal:8080/x', ['X-Forwarded-Host' => $host], null, '1.1', $server), $this->handler());
            self::assertNotNull($this->seen);
            self::assertSame($uri, (string) $this->seen->getUri(), $host);
        }
    }
}
