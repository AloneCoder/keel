<?php

declare(strict_types=1);

namespace Keel\Tests\Http;

use Keel\Http\CallableHandler;
use Keel\Http\Exception\BadRequestException;
use Keel\Http\Middleware\SecurityHeadersConfig;
use Keel\Http\Middleware\SecurityHeadersMiddleware;
use Keel\Http\Middleware\TrustedHostsConfig;
use Keel\Http\Middleware\TrustedHostsMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(SecurityHeadersMiddleware::class)]
#[CoversClass(SecurityHeadersConfig::class)]
#[CoversClass(TrustedHostsMiddleware::class)]
#[CoversClass(TrustedHostsConfig::class)]
final class SecurityHeadersTest extends TestCase
{
    private static function handler(?ResponseInterface $response = null): CallableHandler
    {
        $response ??= new Psr17Factory()->createResponse(200);

        return new CallableHandler(static fn(ServerRequestInterface $request): ResponseInterface => $response);
    }

    public function testDefaultsAndHstsOnHttpsOnly(): void
    {
        $middleware = new SecurityHeadersMiddleware();

        $https = $middleware->process(new ServerRequest('GET', 'https://api.test/'), self::handler());
        self::assertSame('max-age=31536000; includeSubDomains', $https->getHeaderLine('Strict-Transport-Security'));
        self::assertSame('DENY', $https->getHeaderLine('X-Frame-Options'));
        self::assertSame('nosniff', $https->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $https->getHeaderLine('Referrer-Policy'));
        self::assertSame('same-origin', $https->getHeaderLine('Cross-Origin-Opener-Policy'));
        self::assertFalse($https->hasHeader('Content-Security-Policy'), 'no CSP unless configured');
        self::assertFalse($https->hasHeader('Cross-Origin-Resource-Policy'));

        $http = $middleware->process(new ServerRequest('GET', 'http://api.test/'), self::handler());
        self::assertFalse($http->hasHeader('Strict-Transport-Security'), 'browsers ignore HSTS over plain HTTP; it must not be sent');
        self::assertSame('nosniff', $http->getHeaderLine('X-Content-Type-Options'));
    }

    public function testHandlerHeadersWinAndApiPreset(): void
    {
        $middleware = new SecurityHeadersMiddleware(SecurityHeadersConfig::api());
        $own = new Psr17Factory()->createResponse(200)->withHeader('Content-Security-Policy', "default-src 'self'");

        $response = $middleware->process(new ServerRequest('GET', 'https://api.test/docs'), self::handler($own));
        self::assertSame("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'), 'a page may set its own policy');
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));

        $plain = $middleware->process(new ServerRequest('GET', 'https://api.test/x'), self::handler());
        self::assertSame("default-src 'none'; frame-ancestors 'none'", $plain->getHeaderLine('Content-Security-Policy'));
    }

    public function testConfigurableHeaders(): void
    {
        $config = new SecurityHeadersConfig(
            hstsMaxAge: 600,
            hstsIncludeSubDomains: false,
            hstsPreload: true,
            contentSecurityPolicy: "default-src 'self'",
            cspReportOnly: true,
            frameOptions: 'sameorigin',
            noSniff: false,
            referrerPolicy: null,
            permissionsPolicy: ['camera' => [], 'geolocation' => ['self', 'https://maps.test'], 'fullscreen' => ['*']],
            crossOriginOpenerPolicy: null,
            crossOriginResourcePolicy: 'same-site',
            headers: ['X-Permitted-Cross-Domain-Policies' => 'none'],
        );
        self::assertSame('max-age=600; preload', $config->hsts());
        self::assertSame([
            'Content-Security-Policy-Report-Only' => "default-src 'self'",
            'X-Frame-Options' => 'SAMEORIGIN',
            'Permissions-Policy' => 'camera=(), geolocation=(self "https://maps.test"), fullscreen=*',
            'Cross-Origin-Resource-Policy' => 'same-site',
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ], $config->headers());
        self::assertNull(new SecurityHeadersConfig(hstsMaxAge: null)->hsts());
        self::assertSame('camera=()', new SecurityHeadersConfig(permissionsPolicy: 'camera=()')->headers()['Permissions-Policy']);
    }

    public function testInvalidConfigIsRefused(): void
    {
        foreach ([
            static fn() => new SecurityHeadersConfig(frameOptions: 'ALLOW-FROM x'),
            static fn() => new SecurityHeadersConfig(hstsMaxAge: -1),
            static fn() => new SecurityHeadersConfig(contentSecurityPolicy: "default-src 'self'\r\nX-Evil: 1"),
            static fn() => new SecurityHeadersConfig(headers: ["X-A\n" => 'b']),
        ] as $i => $build) {
            try {
                $build();
                self::fail('case ' . $i . ' was accepted');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTrustedHosts(): void
    {
        $config = new TrustedHostsConfig(['api.example.com', '*.example.org', '10.0.0.5', '[::1]']);
        self::assertTrue($config->allows('api.example.com'));
        self::assertTrue($config->allows('API.EXAMPLE.COM'));
        self::assertTrue($config->allows('eu.example.org'));
        self::assertTrue($config->allows('a.b.example.org'), 'any depth');
        self::assertFalse($config->allows('example.org'), 'the wildcard does not cover the apex');
        self::assertFalse($config->allows('evilexample.org'));
        self::assertFalse($config->allows('example.com'));
        self::assertTrue($config->allows('10.0.0.5'));
        self::assertTrue($config->allows('[::1]'));
        self::assertFalse($config->allows(''));
        self::assertTrue(new TrustedHostsConfig(['*'])->allows('anything.test'));

        $middleware = new TrustedHostsMiddleware($config);
        self::assertSame(200, $middleware->process(new ServerRequest('GET', 'https://api.example.com:8443/x'), self::handler())->getStatusCode(), 'the port does not matter');

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Invalid host');
        $middleware->process(new ServerRequest('GET', 'https://attacker.test/reset'), self::handler());
    }

    public function testTrustedHostsConfigValidation(): void
    {
        foreach ([[], ['bad host'], ['*.'], ['http://x.test']] as $hosts) {
            try {
                new TrustedHostsConfig($hosts);
                self::fail(json_encode($hosts, \JSON_THROW_ON_ERROR) . ' was accepted');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
