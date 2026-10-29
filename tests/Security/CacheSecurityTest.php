<?php

declare(strict_types=1);

namespace Keel\Tests\Security;

use Keel\Auth\ApiKeyAuthenticator;
use Keel\Auth\AuthConfig;
use Keel\Auth\BearerTokenAuthenticator;
use Keel\Auth\PrincipalResolver;
use Keel\Cache\ArrayCache;
use Keel\Cache\FileCache;
use Keel\Container\Definitions;
use Keel\Http\CallableHandler;
use Keel\Idempotency\IdempotencyConfig;
use Keel\Idempotency\IdempotencyMiddleware;
use Keel\Kernel\Kernel;
use Keel\RateLimit\CounterStorage;
use Keel\RateLimit\InMemoryCounters;
use Keel\Session\FileStorage;
use Keel\Session\SessionConfig;
use Keel\Session\SessionMiddleware;
use Keel\Testing\TestClient;
use Keel\Tests\Auth\Fixtures\Tokens;
use Keel\Tests\Security\Fixtures\PrivateController;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Stored responses and stored state: one client's response served to another through the output
 * cache or an idempotency key, and cache or session files planted by another local user.
 */
#[Group('security')]
final class CacheSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        PrivateController::$runs = 0;
    }

    /**
     * @param list<class-string<MiddlewareInterface>> $middleware
     */
    private function client(array $middleware = []): TestClient
    {
        $definitions = new Definitions();
        $definitions->instance(CacheInterface::class, new ArrayCache());
        $definitions->set(Tokens::class)->alias(PrincipalResolver::class);
        $definitions->set(InMemoryCounters::class)->alias(CounterStorage::class);
        $definitions->instance(AuthConfig::class, new AuthConfig([BearerTokenAuthenticator::class, ApiKeyAuthenticator::class]));
        $definitions->instance(SessionConfig::class, new SessionConfig('session-secret-that-is-long-enough-32+'));

        return TestClient::forKernel(Kernel::build($definitions, [PrivateController::class], $middleware));
    }

    public function testOutputCacheNeverServesAResponseOfAnApiKeyPrincipalToAnother(): void
    {
        $client = $this->client();
        $client->get('/account', ['X-Api-Key' => 'user-token'])->assertOk()->assertJson(['account_of' => 'u1'])->assertHeaderMissing('X-Cache');
        $client->get('/account', ['X-Api-Key' => 'admin-token'])->assertOk()->assertJson(['account_of' => 'u2', 'run' => 2]);
        $client->get('/account')->assertStatus(401);
    }

    public function testOutputCacheDeclaredBeforeTheGuardNeverSkipsIt(): void
    {
        $client = $this->client();
        $client->get('/report', ['X-Api-Key' => 'user-token'])->assertOk()->assertJson(['report_for' => 'u1'])->assertHeaderMissing('X-Cache');
        $client->get('/report')->assertStatus(401);
    }

    public function testOutputCacheKeepsSessionsApart(): void
    {
        $client = $this->client([SessionMiddleware::class]);
        if (preg_match('/keel_session=([^;]+)/', $client->post('/login')->header('Set-Cookie'), $cookie) !== 1) {
            self::fail('logging in sets the session cookie');
        }

        // A request carrying a session cookie is never cached nor served from the cache.
        $client->withCookie('keel_session', $cookie[1])->get('/welcome')->assertJson(['hello' => 'alice'])->assertHeaderMissing('X-Cache');

        // A visitor whose session the handler wrote to (a CSRF token) leaves nothing to share.
        $first = $client->get('/welcome')->assertJson(['hello' => 'guest', 'run' => 2])->json();
        $second = $client->get('/welcome')->assertJson(['hello' => 'guest', 'run' => 3])->assertHeader('X-Cache', 'MISS')->json();
        self::assertNotSame($first['csrf'], $second['csrf']);
    }

    public function testIdempotencyKeysAreScopedToApiKeyPrincipals(): void
    {
        $client = $this->client();
        $client->post('/payouts', null, ['X-Api-Key' => 'user-token', 'Idempotency-Key' => 'p-1'])->assertOk()->assertJson(['owner' => 'u1']);

        // #[Idempotent] runs before #[Auth] here: a replay must not skip it.
        $client->post('/payouts', null, ['Idempotency-Key' => 'p-1'])->assertStatus(401);
        $client->post('/payouts', null, ['X-Api-Key' => 'admin-token', 'Idempotency-Key' => 'p-1'])
            ->assertJson(['owner' => 'u2', 'payout' => 2])->assertHeaderMissing(IdempotencyMiddleware::REPLAYED);
        $client->post('/payouts', null, ['X-Api-Key' => 'user-token', 'Idempotency-Key' => 'p-1'])
            ->assertJson(['owner' => 'u1', 'payout' => 1])->assertHeader(IdempotencyMiddleware::REPLAYED, 'true');
    }

    public function testIdempotencyKeysAreScopedToCookiesAndNeverReplayOne(): void
    {
        $middleware = new IdempotencyMiddleware(new ArrayCache(), new IdempotencyConfig(), new InMemoryCounters());
        $calls = 0;
        $handler = new CallableHandler(static function () use (&$calls): ResponseInterface {
            $calls++;

            return new Psr17Factory()->createResponse(201)->withHeader('Set-Cookie', 'keel_session=fresh-session-of-the-first-client');
        });
        $request = static fn(array $cookies): ServerRequestInterface => new ServerRequest('POST', '/export', ['Idempotency-Key' => 'e-1'])->withCookieParams($cookies);

        $middleware->process($request(['keel_session' => 'alice']), $handler);
        $replay = $middleware->process($request(['keel_session' => 'alice']), $handler);
        self::assertSame('true', $replay->getHeaderLine(IdempotencyMiddleware::REPLAYED));
        self::assertFalse($replay->hasHeader('Set-Cookie'), 'a cookie is never replayed');

        self::assertFalse($middleware->process($request(['keel_session' => 'mallory']), $handler)->hasHeader(IdempotencyMiddleware::REPLAYED));
        self::assertFalse($middleware->process($request([]), $handler)->hasHeader(IdempotencyMiddleware::REPLAYED));
        self::assertSame(3, $calls);
    }

    public function testSessionFilesInAWorldWritableDirectoryAreNotRead(): void
    {
        $open = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/open-sessions-' . bin2hex(random_bytes(4));
        mkdir($open, 0o777, true);
        chmod($open, 0o777);
        if ((fileperms($open) & 0o002) === 0) {
            self::markTestSkipped('filesystem does not keep world-writable mode bits');
        }
        $id = str_repeat('a', 40);
        file_put_contents($open . '/sess_' . $id . '.json', json_encode(['exp' => time() + 60, 'data' => ['roles' => ['admin']]]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('writable by everyone or owned by someone else');
        new FileStorage($open)->read($id);
    }

    public function testCacheAndSessionDirectoriesOwnedByAnotherUserAreRefused(): void
    {
        $foreign = self::foreignDirectory();
        if ($foreign === null) {
            self::markTestSkipped('no directory owned by another user to test against');
        }

        try {
            new FileCache($foreign)->set('k', 'v');
            self::fail('a cache directory owned by another user is refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('owned by someone else', $e->getMessage());
        }
        try {
            new FileStorage($foreign)->write(str_repeat('a', 40), [], 60);
            self::fail('a session directory owned by another user is refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('owned by someone else', $e->getMessage());
        }
    }

    /**
     * A system directory another user could write to while we cannot tell it from our own by its
     * mode alone: not world-writable, owned by someone else (root only when group-writable).
     */
    private static function foreignDirectory(): ?string
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        foreach (['/var/mail', '/var/spool/mail', '/var/local', '/private/var/networkd'] as $directory) {
            $owner = @fileowner($directory);
            $mode = @fileperms($directory);
            if (!is_dir($directory) || $owner === false || $mode === false || $owner === $uid || ($mode & 0o002) !== 0) {
                continue;
            }
            if ($owner !== 0 || ($mode & 0o020) !== 0) {
                return $directory;
            }
        }

        return null;
    }
}
