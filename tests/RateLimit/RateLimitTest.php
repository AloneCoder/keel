<?php

declare(strict_types=1);

namespace Keel\Tests\RateLimit;

use Keel\Auth\AuthConfig;
use Keel\Auth\AuthMiddleware;
use Keel\Auth\BearerTokenAuthenticator;
use Keel\Auth\PrincipalResolver;
use Keel\Container\Definitions;
use Keel\Http\Exception\TooManyRequestsException;
use Keel\Kernel\Kernel;
use Keel\RateLimit\Attribute\RateLimit;
use Keel\RateLimit\CounterStorage;
use Keel\RateLimit\InMemoryCounters;
use Keel\RateLimit\RateLimiter;
use Keel\RateLimit\RateLimitGuard;
use Keel\RateLimit\RateLimitResult;
use Keel\Testing\TestClient;
use Keel\Tests\Auth\Fixtures\Tokens;
use Keel\Tests\RateLimit\Fixtures\SearchController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(RateLimit::class)]
#[CoversClass(RateLimitGuard::class)]
#[CoversClass(RateLimiter::class)]
#[CoversClass(RateLimitResult::class)]
#[CoversClass(InMemoryCounters::class)]
#[CoversClass(TooManyRequestsException::class)]
final class RateLimitTest extends TestCase
{
    public int $now = 1_700_000_000;

    private function clock(): ClockInterface
    {
        return new class ($this) implements ClockInterface {
            public function __construct(private readonly RateLimitTest $test) {}

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@' . $this->test->now);
            }
        };
    }

    public function testFixedWindowCountsAndResets(): void
    {
        $limiter = new RateLimiter(new InMemoryCounters(), $this->clock());

        $first = $limiter->hit('k', 2, 60);
        self::assertTrue($first->allowed);
        self::assertSame(1, $first->remaining);
        self::assertSame(2, $first->limit);
        self::assertSame(40, $first->resetAfter, 'window aligned to the clock: 1_700_000_000 % 60 = 20');

        self::assertSame(0, $limiter->hit('k', 2, 60)->remaining);
        $third = $limiter->hit('k', 2, 60);
        self::assertFalse($third->allowed);
        self::assertSame(0, $third->remaining);

        self::assertTrue($limiter->hit('other', 2, 60)->allowed, 'keys are independent');

        $this->now += 40;
        self::assertTrue($limiter->hit('k', 2, 60)->allowed, 'a new window starts');
    }

    public function testGuardAddsHeadersAndAnswers429(): void
    {
        $definitions = new Definitions();
        $definitions->instance(ClockInterface::class, $this->clock());
        $definitions->set(InMemoryCounters::class)->alias(CounterStorage::class);
        $client = TestClient::forKernel(Kernel::build($definitions, [SearchController::class]));

        $client->get('/search')->assertOk()->assertHeader('RateLimit-Limit', '2')->assertHeader('RateLimit-Remaining', '1')->assertHeader('RateLimit-Reset', '40');
        $client->get('/search')->assertOk()->assertHeader('RateLimit-Remaining', '0');
        $client->get('/search')
            ->assertProblem(429, 'Rate limit exceeded')
            ->assertHeader('Retry-After', '40')
            ->assertHeader('RateLimit-Remaining', '0');

        $client->withServer(['REMOTE_ADDR' => '10.0.0.9'])->get('/search')->assertOk();
        $client->get('/open')->assertOk()->assertHeaderMissing('RateLimit-Limit');
    }

    public function testPrincipalKeyFallsBackToIp(): void
    {
        $definitions = new Definitions();
        $definitions->set(InMemoryCounters::class)->alias(CounterStorage::class);
        $client = TestClient::forKernel(Kernel::build($definitions, [SearchController::class]));

        $client->get('/mine')->assertOk()->assertHeader('RateLimit-Limit', '1');
        $client->get('/mine')->assertStatus(429);
    }

    public function testAttributeValidatesItsArguments(): void
    {
        self::assertSame(['limit' => 5, 'window' => 10, 'by' => 'principal'], new RateLimit(5, 10, 'principal')->value());
        self::assertSame([RateLimitGuard::class], new RateLimit(5)->middleware());
        self::assertSame(1, new RateLimit(1, 1)->window);

        foreach ([[0, 60, 'ip'], [5, 0, 'ip'], [5, 60, 'header']] as [$limit, $window, $by]) {
            try {
                new RateLimit($limit, $window, $by);
                self::fail('rejected');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('RateLimit', $e->getMessage());
            }
        }
    }

    public function testPrincipalKeyIsolatesUsersAndStorageIsInjected(): void
    {
        $spy = new class implements CounterStorage {
            /** @var list<string> */
            public array $keys = [];

            public function increment(string $key, int $ttl): int
            {
                $this->keys[] = $key;

                return count(array_keys($this->keys, $key, true));
            }
        };
        $definitions = new Definitions();
        $definitions->instance(CounterStorage::class, $spy);
        $definitions->set(RateLimiter::class)->argument('storage', new \Keel\Container\Ref(CounterStorage::class));
        $definitions->set(Tokens::class)->alias(PrincipalResolver::class);
        $definitions->instance(AuthConfig::class, new AuthConfig([BearerTokenAuthenticator::class]));
        $client = TestClient::forKernel(Kernel::build($definitions, [SearchController::class], [AuthMiddleware::class]));

        $client->get('/mine', ['Authorization' => 'Bearer user-token'])->assertOk();
        $client->get('/mine', ['Authorization' => 'Bearer admin-token'])->assertOk();
        $client->get('/mine', ['Authorization' => 'Bearer user-token'])->assertStatus(429);
        $client->get('/mine')->assertOk();
        self::assertStringStartsWith('GET|/mine|principal:u1:', $spy->keys[0]);
        self::assertStringStartsWith('GET|/mine|principal:u2:', $spy->keys[1]);
        self::assertStringStartsWith('GET|/mine|ip:127.0.0.1:', $spy->keys[3], 'anonymous requests count by IP, in their own namespace');

        self::assertInstanceOf(RateLimiter::class, new RateLimiter(), 'default storage works without APCu');
    }

    public function testGuardAuthenticatesByItselfForPrincipalLimitsWithoutTheGlobalMiddleware(): void
    {
        $definitions = new Definitions();
        $definitions->set(InMemoryCounters::class)->alias(CounterStorage::class);
        $definitions->set(Tokens::class)->alias(PrincipalResolver::class);
        $definitions->instance(AuthConfig::class, new AuthConfig([BearerTokenAuthenticator::class]));
        $client = TestClient::forKernel(Kernel::build($definitions, [SearchController::class]));

        $client->get('/mine', ['Authorization' => 'Bearer user-token'])->assertOk();
        $client->get('/mine', ['Authorization' => 'Bearer admin-token'])->assertOk()->assertHeader('RateLimit-Remaining', '0');
        $client->get('/mine', ['Authorization' => 'Bearer user-token'])->assertStatus(429);
        $client->get('/mine')->assertOk()->assertHeader('RateLimit-Limit', '1');
        $client->get('/mine', ['Authorization' => 'Bearer nope'])->assertProblem(401, 'Invalid or expired token');
    }

    public function testInMemoryCountersPruneAtMostOncePerSecondOnceLarge(): void
    {
        $counters = new InMemoryCounters($this->clock());
        $size = static function (InMemoryCounters $counters): int {
            $table = new \ReflectionProperty(InMemoryCounters::class, 'counters')->getValue($counters);

            return is_array($table) ? count($table) : -1;
        };

        for ($i = 0; $i < 10_001; $i++) {
            $counters->increment('k' . $i, -1); // expired on arrival
        }
        self::assertSame(10_001, $size($counters), 'no scan until the table exceeds 10 000 entries');

        self::assertSame(1, $counters->increment('live', 60));
        self::assertSame(1, $size($counters), 'first increment over the threshold prunes the expired entries');

        for ($i = 0; $i < 10_001; $i++) {
            $counters->increment('e' . $i, -1);
        }
        self::assertSame(10_002, $size($counters), 'no second scan within the same second');

        $this->now += 1;
        self::assertSame(2, $counters->increment('live', 60));
        self::assertSame(1, $size($counters), 'a second later the expired entries are gone and the live counter kept');
    }

    public function testInMemoryCountersExpire(): void
    {
        $counters = new InMemoryCounters($this->clock());
        self::assertSame(1, $counters->increment('a', 60));
        self::assertSame(2, $counters->increment('a', 60));
        $this->now += 59;
        self::assertSame(3, $counters->increment('a', 60));
        $this->now += 1;
        self::assertSame(1, $counters->increment('a', 60), 'expired exactly at the deadline');
        $counters->clear();
        self::assertSame(1, $counters->increment('a', 60));
    }


    public function testLimitAndWindowBelowOneAreRejectedBeforeAnyArithmetic(): void
    {
        $limiter = new RateLimiter(new InMemoryCounters(), $this->clock());

        foreach ([[5, 0], [5, -60], [0, 60], [-1, 60]] as [$limit, $window]) {
            try {
                $limiter->hit('k', $limit, $window);
                self::fail(sprintf('accepted limit %d, window %d', $limit, $window));
            } catch (\InvalidArgumentException $e) {
                self::assertSame(sprintf('Rate limit needs a limit and a window of at least 1, got limit %d and window %d', $limit, $window), $e->getMessage());
            }
        }

        self::assertTrue($limiter->hit('k', 1, 1)->allowed, 'the boundary values are valid');
    }
}
