<?php

declare(strict_types=1);

namespace Keel\Tests\Runtime;

use Keel\Http\CallableHandler;
use Keel\Http\Exception\ServiceUnavailableException;
use Keel\Runtime\ApcuInFlightCounter;
use Keel\Runtime\InFlightCounter;
use Keel\Runtime\LoadSheddingConfig;
use Keel\Runtime\LoadSheddingMiddleware;
use Keel\Runtime\LocalInFlightCounter;
use Keel\Tests\Kernel\Fixtures\ArrayLogger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(LoadSheddingMiddleware::class)]
#[CoversClass(LoadSheddingConfig::class)]
#[CoversClass(LocalInFlightCounter::class)]
#[CoversClass(ApcuInFlightCounter::class)]
#[CoversClass(ServiceUnavailableException::class)]
final class LoadSheddingTest extends TestCase
{
    private static function ok(): CallableHandler
    {
        return new CallableHandler(static fn(ServerRequestInterface $request): ResponseInterface => new Psr17Factory()->createResponse(200));
    }

    private static function clock(float $now): ClockInterface
    {
        return new class ($now) implements ClockInterface {
            public function __construct(private readonly float $now) {}

            public function now(): \DateTimeImmutable
            {
                return \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $this->now)) ?: new \DateTimeImmutable();
            }
        };
    }

    private static function shed(LoadSheddingMiddleware $middleware, ServerRequestInterface $request): ?ServiceUnavailableException
    {
        try {
            $middleware->process($request, self::ok());

            return null;
        } catch (ServiceUnavailableException $e) {
            return $e;
        }
    }

    public function testNothingIsShedByDefault(): void
    {
        $middleware = new LoadSheddingMiddleware(counter: new LocalInFlightCounter(), loadAverage: static fn(): float => 99.0, availableMemory: static fn(): int => 1);
        self::assertNull(self::shed($middleware, new ServerRequest('GET', '/')));
    }

    public function testQueueTimeFromTheProxy(): void
    {
        $logger = new ArrayLogger();
        $middleware = new LoadSheddingMiddleware(new LoadSheddingConfig(maxQueueTime: 500, retryAfter: 3), clock: self::clock(1_700_000_010.0), logger: $logger);

        self::assertNull(self::shed($middleware, new ServerRequest('GET', '/', ['X-Request-Start' => 't=1700000009.800'])), 'seconds with a fraction (nginx ${msec}), 200 ms');
        $e = self::shed($middleware, new ServerRequest('GET', '/', ['X-Request-Start' => 't=1700000009000']));
        self::assertNotNull($e, 'milliseconds, 1 s');
        self::assertSame(503, $e->status);
        self::assertSame(['Retry-After' => '3'], $e->headers);
        self::assertSame(['info: Load shed: queued 1000 ms, limit 500 ms'], $logger->records);
        self::assertNotNull(self::shed($middleware, new ServerRequest('GET', '/', ['X-Queue-Start' => '1700000008000000'])), 'microseconds (Heroku), 2 s');
        self::assertNull(self::shed($middleware, new ServerRequest('GET', '/', ['X-Request-Start' => 't=1700000020'])), 'a clock ahead of ours counts as no wait');
        self::assertNull(self::shed($middleware, new ServerRequest('GET', '/', ['X-Request-Start' => 'garbage'])));
        self::assertNull(self::shed($middleware, new ServerRequest('GET', '/')));
    }

    public function testLoadAverageAndMemory(): void
    {
        $load = 1.5;
        $memory = 2 * 1024 ** 3;
        $middleware = new LoadSheddingMiddleware(
            new LoadSheddingConfig(maxLoadAverage: 4.0, minAvailableMemory: '512M'),
            loadAverage: static function () use (&$load): float {
                return $load;
            },
            availableMemory: static function () use (&$memory): int {
                return $memory;
            },
        );

        self::assertNull(self::shed($middleware, new ServerRequest('GET', '/')));
        $load = 6.0;
        self::assertNotNull(self::shed($middleware, new ServerRequest('GET', '/')));
        $load = 1.0;
        $memory = 100 * 1024 ** 2;
        self::assertNotNull(self::shed($middleware, new ServerRequest('GET', '/')));
    }

    public function testInFlightLimitAndExceptions(): void
    {
        $counter = new class implements InFlightCounter {
            public int $current = 0;

            public int $left = 0;

            public function enter(): int
            {
                return ++$this->current;
            }

            public function leave(): void
            {
                $this->current--;
                $this->left++;
            }
        };
        $middleware = new LoadSheddingMiddleware(new LoadSheddingConfig(maxInFlight: 2), $counter);

        self::assertNull(self::shed($middleware, new ServerRequest('GET', '/')));
        $counter->current = 2; // two other workers are busy
        self::assertNotNull(self::shed($middleware, new ServerRequest('GET', '/')), 'the third request is shed');
        self::assertSame(2, $counter->current, 'a shed request is counted out again');
        self::assertNull(self::shed($middleware, new ServerRequest('GET', '/health/live')), 'probes are exempt by default');
        self::assertNull(self::shed($middleware, new ServerRequest('GET', '/metrics')));
        self::assertNotNull(self::shed($middleware, new ServerRequest('GET', '/healthy')), 'prefixes match whole segments');

        $counter->current = 0;
        $failing = new CallableHandler(static fn(): ResponseInterface => throw new \RuntimeException('boom'));
        try {
            $middleware->process(new ServerRequest('GET', '/'), $failing);
        } catch (\RuntimeException) {
        }
        self::assertSame(0, $counter->current, 'counted out when the handler throws');
    }

    public function testLocalCounterAndConfigValidation(): void
    {
        $counter = new LocalInFlightCounter();
        self::assertSame(1, $counter->enter());
        self::assertSame(2, $counter->enter());
        $counter->leave();
        $counter->leave();
        $counter->leave();
        self::assertSame(1, $counter->enter(), 'never below zero');

        self::assertSame(['/status', '/internal'], new LoadSheddingConfig(except: ['status/', '/internal'])->except);
        self::assertSame(512 * 1024 ** 2, new LoadSheddingConfig(minAvailableMemory: '512M')->minAvailableMemory);
        foreach ([
            static fn() => new LoadSheddingConfig(maxInFlight: 0),
            static fn() => new LoadSheddingConfig(maxQueueTime: -1),
            static fn() => new LoadSheddingConfig(maxLoadAverage: 0.0),
            static fn() => new LoadSheddingConfig(retryAfter: -1),
            static fn() => new LoadSheddingConfig(minAvailableMemory: 'lots'),
        ] as $i => $case) {
            try {
                $case();
                self::fail('case ' . $i . ' accepted');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testApcuCounter(): void
    {
        if (!ApcuInFlightCounter::available()) {
            $this->expectException(\RuntimeException::class);
            new ApcuInFlightCounter();

            return;
        }
        $key = 'keel:test:in_flight:' . bin2hex(random_bytes(4));
        $counter = new ApcuInFlightCounter($key);
        self::assertSame(1, $counter->enter());
        self::assertSame(2, $counter->enter());
        $counter->leave();
        $counter->leave();
        $counter->leave();
        self::assertSame(0, apcu_fetch($key), 'clamped at zero after a window rollover');
    }
}
