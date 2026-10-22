<?php

declare(strict_types=1);

namespace Keel\Tests\Session;

use Keel\Container\Definitions;
use Keel\Kernel\Kernel;
use Keel\Session\CsrfConfig;
use Keel\Session\CsrfMiddleware;
use Keel\Session\CsrfToken;
use Keel\Session\SessionConfig;
use Keel\Session\SessionMiddleware;
use Keel\Testing\TestClient;
use Keel\Tests\Session\Fixtures\FormController;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CsrfMiddleware::class)]
#[CoversClass(CsrfConfig::class)]
#[CoversClass(CsrfToken::class)]
final class CsrfTest extends TestCase
{
    private const string SECRET = 'a-secret-that-is-long-enough-for-hmac-signing!';

    private static function client(?CsrfConfig $config = null): TestClient
    {
        $definitions = new Definitions();
        $definitions->instance(SessionConfig::class, new SessionConfig(self::SECRET, ttl: 60));
        $definitions->instance(CsrfConfig::class, $config ?? new CsrfConfig(except: ['/api/']));

        return TestClient::forKernel(Kernel::build($definitions, [FormController::class], [SessionMiddleware::class, CsrfMiddleware::class]));
    }

    private static function cookie(string $setCookie): string
    {
        return explode(';', substr($setCookie, strlen('keel_session=')), 2)[0];
    }

    public function testTokenIsIssuedOnceAndVerifiedOnUnsafeRequests(): void
    {
        $client = self::client();

        $form = $client->get('/form')->assertOk();
        self::assertMatchesRegularExpression('~<input type="hidden" name="_token" value="([a-f0-9]{40})">~', $form->body());
        preg_match('~value="([a-f0-9]{40})"~', $form->body(), $m);
        $token = $m[1] ?? '';
        $cookie = self::cookie($form->header('Set-Cookie'));

        $session = $client->withCookie('keel_session', $cookie);
        $session->get('/form')->assertOk()->assertHeaderMissing('Set-Cookie')->assertBodyContains($token);

        $session->post('/submit', ['_token' => $token])->assertOk()->assertJson(['ok' => true, 'token' => $token]);
        $session->post('/submit', [], ['X-CSRF-Token' => $token])->assertOk();
        $session->json('POST', '/submit', ['_token' => $token])->assertProblem(403, 'Invalid CSRF token');
        $session->post('/submit', ['_token' => strrev($token)])->assertProblem(403, 'Invalid CSRF token');
        $session->post('/submit')->assertProblem(403, 'Invalid CSRF token');
        $client->post('/submit', ['_token' => $token])->assertProblem(403, 'Invalid CSRF token');

        $session->delete('/api/items/3')->assertOk()->assertJson(['deleted' => 3]);
    }

    public function testNeedsTheSession(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('needs the session');
        new CsrfMiddleware()->process(new ServerRequest('GET', '/'), new \Keel\Http\CallableHandler(static fn() => throw new \RuntimeException('unreachable')));
    }

    public function testConfigIsValidated(): void
    {
        self::assertSame('<input type="hidden" name="a&quot;b" value="x">', new CsrfToken('x', 'a"b')->field());
        self::assertNull(CsrfMiddleware::token(new ServerRequest('GET', '/')));

        try {
            new CsrfConfig(field: '');
            self::fail('empty field accepted');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        new CsrfConfig(except: ['api']);
    }
}
