<?php

declare(strict_types=1);

namespace Keel\Tests\Auth;

use Keel\Auth\ApiKeyAuthenticator;
use Keel\Auth\Attribute\Auth;
use Keel\Auth\AuthConfig;
use Keel\Auth\Authentication;
use Keel\Auth\AuthGuard;
use Keel\Auth\AuthMiddleware;
use Keel\Auth\BearerTokenAuthenticator;
use Keel\Auth\HmacAuthenticator;
use Keel\Auth\HmacSecrets;
use Keel\Auth\Identity;
use Keel\Auth\Principal;
use Keel\Auth\PrincipalResolver;
use Keel\Auth\SecurityScheme;
use Keel\Container\Definitions;
use Keel\Kernel\Kernel;
use Keel\Testing\TestClient;
use Keel\Tests\Auth\Fixtures\AccountController;
use Keel\Tests\Auth\Fixtures\Tokens;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(Auth::class)]
#[CoversClass(AuthGuard::class)]
#[CoversClass(AuthMiddleware::class)]
#[CoversClass(Authentication::class)]
#[CoversClass(AuthConfig::class)]
#[CoversClass(BearerTokenAuthenticator::class)]
#[CoversClass(ApiKeyAuthenticator::class)]
#[CoversClass(HmacAuthenticator::class)]
#[CoversClass(Identity::class)]
#[CoversClass(SecurityScheme::class)]
final class AuthTest extends TestCase
{
    private static function definitions(): Definitions
    {
        $definitions = new Definitions();
        $definitions->set(Tokens::class)->alias(PrincipalResolver::class)->alias(HmacSecrets::class);
        $definitions->instance(AuthConfig::class, new AuthConfig([
            BearerTokenAuthenticator::class,
            ApiKeyAuthenticator::class,
            HmacAuthenticator::class,
        ]));

        return $definitions;
    }

    private function client(bool $globalMiddleware = true): TestClient
    {
        return TestClient::forKernel(Kernel::build(self::definitions(), [AccountController::class], $globalMiddleware ? [AuthMiddleware::class] : []));
    }

    public function testAnonymousRequestToProtectedRouteIs401WithEveryChallenge(): void
    {
        $this->client()->get('/me')
            ->assertProblem(401, 'Authentication required')
            ->assertHeader('WWW-Authenticate', 'Bearer realm="api", ApiKey header="X-Api-Key", HMAC');
    }

    public function testBearerTokenResolvesThePrincipalForTheHandler(): void
    {
        $this->client()->get('/me', ['Authorization' => 'Bearer user-token'])
            ->assertOk()
            ->assertJson(['id' => 'u1', 'roles' => ['user']]);
    }

    public function testInvalidTokenIs401EvenOnPublicRoutesWhenAuthMiddlewareIsGlobal(): void
    {
        $this->client()->get('/feed', ['Authorization' => 'Bearer nope'])
            ->assertProblem(401, 'Invalid or expired token')
            ->assertHeader('WWW-Authenticate', 'Bearer realm="api", error="invalid_token"');
    }

    public function testPublicRouteSeesTheOptionalPrincipal(): void
    {
        $client = $this->client();
        $client->get('/feed')->assertOk()->assertJson(['for' => 'anonymous']);
        $client->get('/feed', ['X-Api-Key' => 'admin-token'])->assertOk()->assertJson(['for' => 'u2']);
    }

    public function testRolesAreEnforcedAndConcreteIdentityIsInjected(): void
    {
        $client = $this->client();
        $client->delete('/users/7', ['Authorization' => 'Bearer user-token'])->assertProblem(403, 'Insufficient role');
        $client->delete('/users/7', ['Authorization' => 'Bearer admin-token'])
            ->assertOk()
            ->assertJson(['deleted' => 7, 'by' => 'u2', 'tenant' => 'acme']);
    }

    public function testGuardAuthenticatesByItselfWithoutTheGlobalMiddleware(): void
    {
        $client = $this->client(false);
        $client->get('/me')->assertProblem(401);
        $client->get('/me', ['Authorization' => 'Bearer admin-token'])->assertOk()->assertJsonPath('id', 'u2');
        $client->get('/feed', ['Authorization' => 'Bearer nope'])->assertOk()->assertJson(['for' => 'anonymous']);
    }

    public function testApiKeyHeader(): void
    {
        $client = $this->client();
        $client->get('/me', ['X-Api-Key' => 'user-token'])->assertOk()->assertJsonPath('id', 'u1');
        $client->get('/me', ['X-Api-Key' => ' user-token '])->assertOk()->assertJsonPath('id', 'u1');
        $client->get('/me', ['X-Api-Key' => 'wrong'])->assertProblem(401, 'Invalid API key')->assertHeader('WWW-Authenticate', 'ApiKey header="X-Api-Key"');
    }

    public function testHmacHeaderParsingEdges(): void
    {
        $body = '';
        $now = time();
        $signed = HmacAuthenticator::sign('svc', 'top-secret', 'post', '/ingest', $body, $now);
        $client = $this->client();
        $client->request('POST', '/ingest', ['Authorization' => $signed], $body)->assertOk()->assertJson(['key' => 'svc']);
        $client->request('POST', '/ingest', ['Authorization' => 'hmac' . substr($signed, 4)], $body)->assertOk();
        $upperHex = substr($signed, 0, -64) . strtoupper(substr($signed, -64));
        $client->request('POST', '/ingest', ['Authorization' => $upperHex], $body)->assertOk();
        $client->request('POST', '/ingest', ['Authorization' => $signed . ' extra'], $body)->assertProblem(401, 'Malformed HMAC authorization header');

        $authenticator = new HmacAuthenticator(new Tokens());
        $principal = $authenticator->authenticate(new ServerRequest('POST', 'http://localhost/ingest', ['Authorization' => $signed]));
        self::assertInstanceOf(Identity::class, $principal);
        self::assertSame('hmac', $principal->get('scheme'));
        self::assertSame('http', $authenticator->securityScheme()->definition['type']);
    }

    public function testHmacToleranceIsInclusive(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@1700000000');
            }
        };
        $authenticator = new HmacAuthenticator(new Tokens(), 60, $clock);
        $request = static fn(int $timestamp): ServerRequest => new ServerRequest('GET', 'http://localhost/x', ['Authorization' => HmacAuthenticator::sign('svc', 'top-secret', 'GET', '/x', '', $timestamp)]);

        self::assertNotNull($authenticator->authenticate($request(1700000000 - 60)));
        $this->expectExceptionMessage('Signature timestamp out of range');
        $authenticator->authenticate($request(1700000000 - 61));
    }

    public function testIdentityAttributesAndPrincipalLookup(): void
    {
        $identity = new Identity('u9', ['ops'], ['tenant' => 'acme', 'flag' => null]);
        self::assertSame('acme', $identity->get('tenant', 'other'));
        self::assertSame('dflt', $identity->get('missing', 'dflt'));
        self::assertSame('dflt', $identity->get('flag', 'dflt'), 'a null attribute falls back like a missing one');
        self::assertSame(['tenant' => 'acme', 'flag' => null], $identity->attributes());
        self::assertTrue($identity->hasRole('ops'));
        self::assertFalse($identity->hasRole('admin'));

        $request = new ServerRequest('GET', '/');
        self::assertNull(Authentication::principal($request));
        self::assertNull(Authentication::principal($request->withAttribute(Principal::class, 'not a principal')));
        self::assertSame($identity, Authentication::principal(Authentication::attach($request, $identity)));
        self::assertSame($identity, Authentication::attach($request, $identity)->getAttribute(Identity::class));
    }

    public function testHmacSignedRequest(): void
    {
        $body = '{"n":1}';
        $now = time();
        $header = HmacAuthenticator::sign('svc', 'top-secret', 'POST', '/ingest', $body, $now);

        $this->client()->request('POST', '/ingest', ['Authorization' => $header, 'Content-Type' => 'application/json'], $body)
            ->assertOk()
            ->assertJson(['key' => 'svc']);

        $tampered = HmacAuthenticator::sign('svc', 'top-secret', 'POST', '/ingest', '{"n":2}', $now);
        $this->client()->request('POST', '/ingest', ['Authorization' => $tampered, 'Content-Type' => 'application/json'], $body)
            ->assertProblem(401, 'Invalid signature')
            ->assertHeader('WWW-Authenticate', 'HMAC');

        $unknown = HmacAuthenticator::sign('other', 'top-secret', 'POST', '/ingest', $body, $now);
        $this->client()->request('POST', '/ingest', ['Authorization' => $unknown], $body)->assertProblem(401, 'Unknown key id');

        $stale = HmacAuthenticator::sign('svc', 'top-secret', 'POST', '/ingest', $body, $now - 3600);
        $this->client()->request('POST', '/ingest', ['Authorization' => $stale], $body)->assertProblem(401, 'Signature timestamp out of range');

        $this->client()->request('POST', '/ingest', ['Authorization' => 'HMAC garbage'], $body)->assertProblem(401, 'Malformed HMAC authorization header');
    }

    public function testHmacCoversTheQueryStringAndUsesTheClock(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@1700000000');
            }
        };
        $authenticator = new HmacAuthenticator(new Tokens(), 60, $clock);

        $signed = HmacAuthenticator::sign('svc', 'top-secret', 'GET', '/ingest?a=1', '', 1700000030);
        $request = new ServerRequest('GET', 'http://localhost/ingest?a=1', ['Authorization' => $signed]);
        self::assertSame('svc', $authenticator->authenticate($request)?->id());

        $other = new ServerRequest('GET', 'http://localhost/ingest?a=2', ['Authorization' => $signed]);
        $this->expectExceptionMessage('Invalid signature');
        $authenticator->authenticate($other);
    }

    public function testSecuritySchemesDescribeEachAuthenticator(): void
    {
        self::assertSame(['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'], new BearerTokenAuthenticator(new Tokens(), format: 'JWT')->securityScheme()->definition);
        self::assertSame(['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Key'], new ApiKeyAuthenticator(new Tokens(), 'X-Key')->securityScheme()->definition);
        self::assertSame('hmac', new HmacAuthenticator(new Tokens())->securityScheme()->name);
        self::assertSame('ApiKey header="X-Key"', new ApiKeyAuthenticator(new Tokens(), 'X-Key')->challenge());
    }

    public function testAttributeStoresRolesAsRouteOption(): void
    {
        $auth = new Auth('admin', 'ops');
        self::assertSame('auth', $auth->key());
        self::assertSame(['roles' => ['admin', 'ops']], $auth->value());
        self::assertSame([AuthGuard::class], $auth->middleware());
    }

    public function testUnauthorizedWithoutAuthenticatorsHasNoChallenge(): void
    {
        $definitions = new Definitions();
        $kernel = Kernel::build($definitions, [AccountController::class]);
        $exception = $kernel->container->get(Authentication::class)->unauthorized();
        self::assertSame([], $exception->headers);
        self::assertSame([], $kernel->container->get(Authentication::class)->authenticators());
        self::assertCount(3, Kernel::build(self::definitions(), [AccountController::class])->container->get(Authentication::class)->authenticators());
        TestClient::forKernel($kernel)->get('/me')->assertProblem(401)->assertHeaderMissing('WWW-Authenticate');
    }
}
