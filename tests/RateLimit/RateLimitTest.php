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
        self::assertStringStartsWith('/mine|u1:', $spy->keys[0]);
        self::assertStringStartsWith('/mine|u2:', $spy->keys[1]);
        self::assertStringStartsWith('/mine|127.0.0.1:', $spy->keys[3]);

        self::assertInstanceOf(RateLimiter::class, new RateLimiter(), 'default storage works without APCu');
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
}
