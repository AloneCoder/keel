<?php

declare(strict_types=1);

namespace Keel\Tests\Security;

use Keel\Auth\ApiKeyAuthenticator;
use Keel\Auth\AuthConfig;
use Keel\Auth\AuthMiddleware;
use Keel\Auth\BearerTokenAuthenticator;
use Keel\Auth\HmacAuthenticator;
use Keel\Auth\HmacSecrets;
use Keel\Auth\PrincipalResolver;
use Keel\Container\Definitions;
use Keel\Kernel\Kernel;
use Keel\RateLimit\CounterStorage;
use Keel\RateLimit\InMemoryCounters;
use Keel\Testing\TestClient;
use Keel\Tests\Auth\Fixtures\AccountController;
use Keel\Tests\Auth\Fixtures\Tokens;
use Keel\Tests\RateLimit\Fixtures\SearchController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Authentication and rate limiting under hostile input: scheme confusion, replay, signature
 * forgery, header spoofing and limit evasion.
 */
#[Group('security')]
final class AuthSecurityTest extends TestCase
{
    private function client(): TestClient
    {
        $definitions = new Definitions();
        $definitions->set(Tokens::class)->alias(PrincipalResolver::class)->alias(HmacSecrets::class);
        $definitions->set(InMemoryCounters::class)->alias(CounterStorage::class);
        $definitions->instance(AuthConfig::class, new AuthConfig([BearerTokenAuthenticator::class, ApiKeyAuthenticator::class, HmacAuthenticator::class]));

        return TestClient::forKernel(Kernel::build($definitions, [AccountController::class, SearchController::class], [AuthMiddleware::class]));
    }

    /**
     * @return iterable<string, array{0: array<string, string>}>
     */
    public static function rejectedCredentials(): iterable
    {
        yield 'empty bearer' => [['Authorization' => 'Bearer ']];
        yield 'bearer with embedded space' => [['Authorization' => 'Bearer user-token extra']];
        yield 'basic scheme' => [['Authorization' => 'Basic dXNlcjpwYXNz']];
        yield 'bearer not at the start' => [['Authorization' => 'Basic Bearer user-token']];
        yield 'token as api key' => [['X-Api-Key' => 'Bearer user-token']];
        yield 'hmac without fields' => [['Authorization' => 'HMAC ']];
        yield 'hmac uppercase hex of a wrong signature' => [['Authorization' => 'HMAC svc:' . time() . ':' . str_repeat('A', 64)]];
        yield 'hmac timestamp in the far future' => [['Authorization' => HmacAuthenticator::sign('svc', 'top-secret', 'GET', '/me', '', time() + 3600)]];
        yield 'hmac signed for another path' => [['Authorization' => HmacAuthenticator::sign('svc', 'top-secret', 'GET', '/feed', '', time())]];
        yield 'hmac signed for another method' => [['Authorization' => HmacAuthenticator::sign('svc', 'top-secret', 'POST', '/me', '', time())]];
        yield 'hmac signed with another secret' => [['Authorization' => HmacAuthenticator::sign('svc', 'wrong', 'GET', '/me', '', time())]];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('rejectedCredentials')]
    public function testMalformedOrForgedCredentialsNeverAuthenticate(array $headers): void
    {
        $response = $this->client()->get('/me', $headers);
        self::assertSame(401, $response->status(), $response->body());
        self::assertStringNotContainsString('u1', $response->body());
    }

    public function testBearerSchemeIsCaseInsensitiveButTokenIsExact(): void
    {
        $client = $this->client();
        $client->get('/me', ['Authorization' => 'bearer user-token'])->assertOk();
        $client->get('/me', ['Authorization' => 'Bearer USER-TOKEN'])->assertStatus(401);
    }

    public function testHmacReplayOnAnotherBodyFails(): void
    {
        $header = HmacAuthenticator::sign('svc', 'top-secret', 'POST', '/ingest', '{"amount":1}', time());
        $client = $this->client();
        $client->request('POST', '/ingest', ['Authorization' => $header], '{"amount":1}')->assertOk();
        $client->request('POST', '/ingest', ['Authorization' => $header], '{"amount":1000}')->assertStatus(401);
    }

    public function testExactHmacReplayFailsWhenACounterStorageIsRegistered(): void
    {
        $header = HmacAuthenticator::sign('svc', 'top-secret', 'POST', '/ingest', '{"amount":1}', time());
        $client = $this->client();
        $client->request('POST', '/ingest', ['Authorization' => $header], '{"amount":1}')->assertOk();
        $client->request('POST', '/ingest', ['Authorization' => $header], '{"amount":1}')->assertProblem(401, 'Replayed signature');
    }

    public function testUnknownKeyIdDoesNotRevealItself(): void
    {
        $client = $this->client();
        $unknown = $client->get('/me', ['Authorization' => HmacAuthenticator::sign('nobody', 'top-secret', 'GET', '/me', '', time())]);
        $wrong = $client->get('/me', ['Authorization' => HmacAuthenticator::sign('svc', 'wrong', 'GET', '/me', '', time())]);
        self::assertSame(401, $unknown->status());
        self::assertSame($wrong->body(), $unknown->body(), 'same problem body for an unknown key id and a wrong signature');
    }

    public function testMalformedBearerIsRejectedOnPublicRoutesToo(): void
    {
        $this->client()->get('/feed', ['Authorization' => 'Bearer a b'])->assertProblem(401, 'Malformed bearer token');
    }

    public function testForwardedHeadersDoNotResetTheRateLimitWithoutTrustedProxies(): void
    {
        $client = $this->client();
        $client->get('/search')->assertOk();
        $client->get('/search')->assertOk();
        $client->get('/search', ['X-Forwarded-For' => '1.2.3.4'])->assertStatus(429);
        $client->get('/search', ['X-Real-Ip' => '5.6.7.8'])->assertStatus(429);
    }

    public function testProblemBodiesDoNotLeakCredentials(): void
    {
        $response = $this->client()->get('/me', ['Authorization' => 'Bearer super-secret-value']);
        self::assertSame(401, $response->status());
        self::assertStringNotContainsString('super-secret-value', $response->body());
        self::assertStringNotContainsString('super-secret-value', $response->header('WWW-Authenticate'));
    }
}
