<?php

declare(strict_types=1);

namespace Keel\Tests\Security;

use Keel\Container\Definitions;
use Keel\Http\CallableHandler;
use Keel\Http\Middleware\CorsConfig;
use Keel\Http\Middleware\CorsMiddleware;
use Keel\Http\Middleware\MethodOverrideMiddleware;
use Keel\Http\Middleware\RequestIdMiddleware;
use Keel\Http\Middleware\TrustedProxiesConfig;
use Keel\Http\Middleware\TrustedProxiesMiddleware;
use Keel\Kernel\Kernel;
use Keel\Routing\MatchStatus;
use Keel\Routing\Router;
use Keel\Session\FileStorage;
use Keel\Session\Session;
use Keel\Session\SessionConfig;
use Keel\Session\SessionMiddleware;
use Keel\Testing\TestClient;
use Keel\Tests\Kernel\Fixtures\Clock;
use Keel\Tests\Kernel\Fixtures\UserController;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Regression suite for the classes of bugs that hit PHP micro-frameworks in 2026:
 * placeholder-constraint bypasses, method override abuse, header injection, host poisoning,
 * cookie tampering, information leaks and state carried between requests.
 */
#[Group('security')]
final class SecurityTest extends TestCase
{
    private function client(bool $debug = false): TestClient
    {
        $definitions = new Definitions();
        $definitions->set(Clock::class);

        return TestClient::forKernel(Kernel::build($definitions, [UserController::class], [], $debug));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function constraintBypassAttempts(): iterable
    {
        yield 'encoded digits' => ['/api/users/%34%32'];
        yield 'trailing letters' => ['/api/users/42abc'];
        yield 'newline after digits' => ['/api/users/42%0A'];
        yield 'encoded slash in segment' => ['/api/users/42%2Fposts'];
        yield 'unicode digits' => ['/api/users/%D9%A4%D9%A2'];
        yield 'double encoding' => ['/api/users/%2534'];
        yield 'dot segments are not resolved by the router' => ['/api/users/../users/42'];
        yield 'null byte' => ['/api/users/42%00'];
    }

    #[DataProvider('constraintBypassAttempts')]
    public function testPlaceholderConstraintsCannotBeBypassed(string $path): void
    {
        self::assertSame(MatchStatus::NotFound, Router::fromControllers(UserController::class)->match('GET', $path)->status, $path);
    }

    public function testMethodOverrideIsOptInAndLimited(): void
    {
        $client = $this->client();
        // Without the middleware the header is inert: a POST stays a POST.
        $client->post('/api/users/1', [], ['X-HTTP-Method-Override' => 'DELETE'])->assertStatus(405);

        $seen = [];
        $handler = new CallableHandler(static function (ServerRequestInterface $r) use (&$seen): ResponseInterface {
            $seen[] = $r->getMethod();

            return new Psr17Factory()->createResponse();
        });
        $override = new MethodOverrideMiddleware();
        $override->process(new ServerRequest('GET', '/', ['X-HTTP-Method-Override' => 'DELETE']), $handler);
        $override->process(new ServerRequest('POST', '/', ['X-HTTP-Method-Override' => 'CONNECT']), $handler);
        $override->process(new ServerRequest('POST', '/', ['X-HTTP-Method-Override' => 'PATCH']), $handler);
        self::assertSame(['GET', 'POST', 'PATCH'], $seen, 'only POST, only to the allowed set');
    }

    public function testRequestIdCannotInjectHeaders(): void
    {
        $handler = new CallableHandler(static fn(ServerRequestInterface $r): ResponseInterface => new Psr17Factory()->createResponse());
        foreach (['abc def', 'a;b', str_repeat('x', 129), 'x/y', '"quoted"'] as $bad) {
            $response = new RequestIdMiddleware()->process(new ServerRequest('GET', '/', ['X-Request-Id' => $bad]), $handler);
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $response->getHeaderLine('X-Request-Id'), $bad);
        }
    }

    public function testForwardedHeadersAreIgnoredFromUntrustedPeersAndValidatedFromTrustedOnes(): void
    {
        $seen = null;
        $handler = new CallableHandler(static function (ServerRequestInterface $r) use (&$seen): ResponseInterface {
            $seen = $r;

            return new Psr17Factory()->createResponse();
        });
        $middleware = new TrustedProxiesMiddleware(new TrustedProxiesConfig(['10.0.0.0/8']));
        $poison = ['X-Forwarded-Host' => 'evil.test', 'X-Forwarded-Proto' => 'javascript', 'X-Forwarded-For' => '1.1.1.1'];

        $middleware->process(new ServerRequest('GET', 'http://app.test/', $poison, null, '1.1', ['REMOTE_ADDR' => '203.0.113.5']), $handler);
        self::assertNotNull($seen);
        self::assertSame('app.test', $seen->getUri()->getHost(), 'untrusted peer cannot poison the host');
        self::assertSame('203.0.113.5', TrustedProxiesMiddleware::clientIp($seen));

        $middleware->process(new ServerRequest('GET', 'http://app.test/', ['X-Forwarded-Host' => 'evil.test/path?x', 'X-Forwarded-Proto' => 'javascript'], null, '1.1', ['REMOTE_ADDR' => '10.0.0.9']), $handler);
        self::assertSame('app.test', $seen->getUri()->getHost(), 'malformed host from a trusted proxy is rejected');
        self::assertSame('http', $seen->getUri()->getScheme(), 'unknown scheme is rejected');
    }

    public function testCorsNeverReflectsArbitraryOriginsWithCredentials(): void
    {
        $handler = new CallableHandler(static fn(): ResponseInterface => new Psr17Factory()->createResponse());
        $cors = new CorsMiddleware(new Psr17Factory(), new CorsConfig(allowedOrigins: ['https://app.test'], allowCredentials: true));

        $response = $cors->process(new ServerRequest('GET', '/', ['Origin' => 'https://app.test.evil.test']), $handler);
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));

        $response = $cors->process(new ServerRequest('GET', '/', ['Origin' => 'null']), $handler);
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testSessionCookiesCannotBeForgedOrUsedAsPaths(): void
    {
        $secret = 'session-secret-that-is-long-enough-32+';
        $middleware = new SessionMiddleware(new SessionConfig($secret));
        $handler = new CallableHandler(static function (ServerRequestInterface $r): ResponseInterface {
            $session = $r->getAttribute(Session::class);
            self::assertInstanceOf(Session::class, $session);
            self::assertTrue($session->isNew(), 'forged cookie must not restore a session');

            return new Psr17Factory()->createResponse();
        });

        $forged = rtrim(strtr(base64_encode(json_encode(['exp' => time() + 999, 'data' => ['user' => 'admin']]) ?: ''), '+/', '-_'), '=');
        $middleware->process(new ServerRequest('GET', '/')->withCookieParams(['keel_session' => $forged . '.AAAA']), $handler);
        $middleware->process(new ServerRequest('GET', '/')->withCookieParams(['keel_session' => $forged]), $handler);

        $storage = new FileStorage(sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/sec-sessions');
        self::assertNull($storage->read('../../etc/passwd'));
        self::assertNull($storage->read('sess_x'));
        $this->expectException(\InvalidArgumentException::class);
        $storage->write('../escape', [], 60);
    }

    public function testProductionErrorsDoNotLeakInternals(): void
    {
        $response = $this->client()->get('/api/users/boom', ['Accept' => 'application/json']);
        $body = $response->body();

        self::assertSame(500, $response->status());
        self::assertStringNotContainsString('Something broke', $body);
        self::assertStringNotContainsString('RuntimeException', $body);
        self::assertStringNotContainsString('.php', $body);

        $html = $this->client()->get('/api/users/boom', ['Accept' => 'text/html'])->body();
        self::assertStringNotContainsString('Something broke', $html);
        self::assertStringNotContainsString('/src/', $html);
    }

    public function testDebugPageEscapesAndRedacts(): void
    {
        $html = $this->client(debug: true)
            ->get('/api/users/boom', ['Accept' => 'text/html', 'User-Agent' => '<script>alert(1)</script>', 'Cookie' => 'keel_session=top-secret-value'])
            ->body();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html, 'header values are escaped');
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('top-secret-value', $html, 'cookies are redacted');
    }

    public function testJsonBodyLimitsAndTypeConfusionAreRejected(): void
    {
        $client = $this->client();

        $deep = str_repeat('[', 600) . str_repeat(']', 600);
        $client->request('POST', '/api/users', ['Content-Type' => 'application/json'], $deep)->assertProblem(400);

        $client->request('POST', '/api/users', ['Content-Type' => 'application/json'], '"string"')->assertProblem(400);

        // Nested objects where scalars are expected never reach the DTO.
        $client->json('POST', '/api/users', ['name' => ['nested'], 'email' => ['x' => 'y']])
            ->assertProblem(400, 'Invalid request data', ['name: must be a string', 'email: must be a string']);
    }

    public function testNoStateLeaksBetweenRequestsOfOneKernel(): void
    {
        $client = $this->client();

        $client->json('POST', '/api/users', ['name' => 'Ann', 'email' => 'a@b.c'])->assertStatus(201);
        $client->get('/api/users/1')->assertOk()->assertJsonPath('hits', 1);
        $client->get('/api/users/1')->assertOk()->assertJsonPath('hits', 1);
        $client->get('/api/users/secret', ['X-Token' => 'secret'])->assertOk();
        $client->get('/api/users/secret')->assertStatus(401);
    }
}
