<?php

declare(strict_types=1);

namespace Keel\Tests\HttpClient;

use Keel\Cache\ArrayCache;
use Keel\Http\Stream\IteratorStream;
use Keel\HttpClient\CircuitBreaker;
use Keel\HttpClient\CircuitBreakerClient;
use Keel\HttpClient\CircuitBreakerConfig;
use Keel\HttpClient\CircuitOpenException;
use Keel\HttpClient\CircuitState;
use Keel\HttpClient\NetworkException;
use Keel\HttpClient\RequestException;
use Keel\HttpClient\RetryConfig;
use Keel\HttpClient\RetryingClient;
use Keel\Tests\HttpClient\Fixtures\RecordingLogger;
use Keel\Tests\HttpClient\Fixtures\ScriptedClient;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(RetryingClient::class)]
#[CoversClass(RetryConfig::class)]
#[CoversClass(CircuitBreaker::class)]
#[CoversClass(CircuitBreakerClient::class)]
#[CoversClass(CircuitBreakerConfig::class)]
#[CoversClass(CircuitOpenException::class)]
#[CoversClass(CircuitState::class)]
final class RetryAndCircuitBreakerTest extends TestCase
{
    /** @var list<float> */
    private array $slept = [];

    private float $now = 1_000_000.0;

    /**
     * @param list<int|ResponseInterface|\Throwable> $script
     */
    private function scripted(array $script): ScriptedClient
    {
        return new ScriptedClient($script);
    }

    private function retrying(ClientInterface $client, RetryConfig $config = new RetryConfig(jitter: false), ?RecordingLogger $logger = null): RetryingClient
    {
        return new RetryingClient($client, $config, function (float $seconds): void {
            $this->slept[] = $seconds;
        }, $this->clock(), $logger);
    }

    private function clock(): ClockInterface
    {
        return new class ($this->now(...)) implements ClockInterface {
            public function __construct(private readonly \Closure $now) {}

            public function now(): \DateTimeImmutable
            {
                /** @var float $now */
                $now = ($this->now)();

                return \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $now)) ?: throw new \LogicException('bad time');
            }
        };
    }

    private function now(): float
    {
        return $this->now;
    }

    private function breaker(?ArrayCache $store = null, int $failures = 3, ?RecordingLogger $logger = null): CircuitBreaker
    {
        return new CircuitBreaker(new CircuitBreakerConfig(failures: $failures, openFor: 30, shared: false), $store ?? new ArrayCache(), $this->clock(), $logger);
    }

    private static function logger(): RecordingLogger
    {
        return new RecordingLogger();
    }

    public function testTransientStatusesAreRetriedWithExponentialBackoff(): void
    {
        $inner = $this->scripted([503, 502, 200]);
        $response = $this->retrying($inner)->sendRequest(new Request('GET', 'https://api.test/items'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(3, $inner->calls());
        self::assertSame([0.1, 0.2], $this->slept);
    }

    public function testTheLastResponseIsReturnedWhenAttemptsRunOut(): void
    {
        $inner = $this->scripted([503, 503, 503, 200]);
        $response = $this->retrying($inner)->sendRequest(new Request('GET', 'https://api.test/items'));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(3, $inner->calls());
    }

    public function testOtherStatusesAreReturnedAtOnce(): void
    {
        $inner = $this->scripted([404]);
        self::assertSame(404, $this->retrying($inner)->sendRequest(new Request('GET', 'https://api.test/x'))->getStatusCode());
        self::assertSame(1, $inner->calls());
        self::assertSame([], $this->slept);
    }

    public function testOnlyRequestsSafeToRepeatAreRetried(): void
    {
        $post = $this->scripted([503, 200]);
        self::assertSame(503, $this->retrying($post)->sendRequest(new Request('POST', 'https://api.test/orders', [], '{}'))->getStatusCode());
        self::assertSame(1, $post->calls(), 'POST is not idempotent');

        $keyed = $this->scripted([503, 200]);
        $request = new Request('POST', 'https://api.test/orders', ['Idempotency-Key' => 'k1'], '{"item":"book"}');
        self::assertSame(200, $this->retrying($keyed)->sendRequest($request)->getStatusCode());
        self::assertSame(['{"item":"book"}', '{"item":"book"}'], $keyed->bodies, 'the body is rewound for the second attempt');

        $unseekable = new IteratorStream(['data']);
        $once = $this->scripted([503, 200]);
        self::assertSame(503, $this->retrying($once)->sendRequest(new Request('PUT', 'https://api.test/blob', [], $unseekable))->getStatusCode());
        self::assertSame(1, $once->calls(), 'a body that cannot be rewound is sent once');

        $custom = $this->scripted([503, 200]);
        $config = new RetryConfig(jitter: false, methods: ['get'], idempotencyHeader: null);
        self::assertSame(503, $this->retrying($custom, $config)->sendRequest(new Request('PUT', 'https://api.test/x', ['Idempotency-Key' => 'k']))->getStatusCode());
        self::assertSame(200, $this->retrying($this->scripted([503, 200]), $config)->sendRequest(new Request('GET', 'https://api.test/x'))->getStatusCode());
    }

    public function testNetworkFailuresAreRetriedAndTheLastOneThrown(): void
    {
        $failure = new NetworkException(new Request('GET', '/'), 'connection refused');
        $inner = $this->scripted([$failure, 200]);
        self::assertSame(200, $this->retrying($inner)->sendRequest(new Request('GET', 'https://api.test/x'))->getStatusCode());

        $down = $this->scripted([$failure, $failure, $failure]);
        try {
            $this->retrying($down)->sendRequest(new Request('GET', 'https://api.test/x'));
            self::fail('expected a NetworkException');
        } catch (NetworkException $e) {
            self::assertSame('connection refused', $e->getMessage());
        }
        self::assertSame(3, $down->calls());

        $broken = $this->scripted([new RequestException(new Request('GET', '/'), 'bad scheme'), 200]);
        try {
            $this->retrying($broken)->sendRequest(new Request('GET', 'https://api.test/x'));
            self::fail('expected a RequestException');
        } catch (RequestException) {
            self::assertSame(1, $broken->calls(), 'a request that cannot be sent is not repeated');
        }
    }

    public function testRetryAfterReplacesTheBackoffUpToTheMaximum(): void
    {
        $seconds = $this->scripted([new Response(429, ['Retry-After' => '1']), 200]);
        self::assertSame(200, $this->retrying($seconds)->sendRequest(new Request('GET', 'https://api.test/x'))->getStatusCode());
        self::assertSame([1.0], $this->slept);

        $this->slept = [];
        $date = gmdate('D, d M Y H:i:s \G\M\T', (int) $this->now + 2);
        $dated = $this->scripted([new Response(503, ['Retry-After' => $date]), 200]);
        self::assertSame(200, $this->retrying($dated)->sendRequest(new Request('GET', 'https://api.test/x'))->getStatusCode());
        self::assertSame([2.0], $this->slept);

        $this->slept = [];
        $long = $this->scripted([new Response(503, ['Retry-After' => '120']), 200]);
        self::assertSame(503, $this->retrying($long)->sendRequest(new Request('GET', 'https://api.test/x'))->getStatusCode());
        self::assertSame([], $this->slept, 'waiting two minutes would hold the worker: the response is returned');

        $garbage = $this->scripted([new Response(503, ['Retry-After' => 'soon']), 200]);
        self::assertSame(200, $this->retrying($garbage)->sendRequest(new Request('GET', 'https://api.test/x'))->getStatusCode());
        self::assertSame([0.1], $this->slept, 'an unreadable Retry-After falls back to the backoff');
    }

    public function testBackoffIsCappedAndJittered(): void
    {
        $config = new RetryConfig(maxAttempts: 6, delay: 0.5, maxDelay: 1.5, multiplier: 2.0);
        self::assertSame([0.5, 1.0, 1.5, 1.5], array_map($config->backoff(...), [1, 2, 3, 4]));

        $inner = $this->scripted([503, 503, 503, 503, 503, 200]);
        $this->retrying($inner, $config)->sendRequest(new Request('GET', 'https://api.test/x'));
        self::assertCount(5, $this->slept);
        foreach ($this->slept as $i => $slept) {
            $nominal = $config->backoff($i + 1);
            self::assertGreaterThanOrEqual($nominal / 2, $slept);
            self::assertLessThanOrEqual($nominal, $slept);
        }

        $this->slept = [];
        $this->retrying($this->scripted([503, 200]), new RetryConfig(delay: 0.0))->sendRequest(new Request('GET', 'https://api.test/x'));
        self::assertSame([], $this->slept, 'no delay, no sleep');
    }

    public function testRetriesAreLoggedWithoutTheQueryString(): void
    {
        $logger = self::logger();
        $this->retrying($this->scripted([503, 200]), logger: $logger)->sendRequest(new Request('GET', 'https://user:pw@api.test/x?token=secret'));

        self::assertSame([['info', 'Retrying GET https://api.test/x in 0.1 s after status 503 (attempt 2 of 3)']], $logger->records);
    }

    public function testRetryConfigIsValidated(): void
    {
        foreach ([
            static fn(): RetryConfig => new RetryConfig(maxAttempts: 0),
            static fn(): RetryConfig => new RetryConfig(delay: -1),
            static fn(): RetryConfig => new RetryConfig(multiplier: 0.5),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCircuitOpensAfterConsecutiveFailuresAndFailsFast(): void
    {
        $inner = $this->scripted([500, 503, 502, 200]);
        $client = new CircuitBreakerClient($inner, $breaker = $this->breaker());

        foreach ([500, 503, 502] as $status) {
            self::assertSame($status, $client->sendRequest(new Request('GET', 'https://api.test/x'))->getStatusCode());
        }
        self::assertSame(CircuitState::Open, $breaker->state('https://api.test:443'));

        try {
            $client->sendRequest(new Request('GET', 'https://API.test:443/other'));
            self::fail('expected CircuitOpenException');
        } catch (CircuitOpenException $e) {
            self::assertSame('https://api.test:443', $e->circuit);
            self::assertSame('/other', $e->getRequest()->getUri()->getPath());
            self::assertInstanceOf(\Psr\Http\Client\NetworkExceptionInterface::class, $e);
        }
        self::assertSame(3, $inner->calls(), 'the open circuit did not call the host');

        $other = $this->scripted([200]);
        self::assertSame(200, new CircuitBreakerClient($other, $breaker)->sendRequest(new Request('GET', 'http://api.test/x'))->getStatusCode(), 'another origin has its own circuit');
    }

    public function testASuccessResetsTheCountAndOtherOutcomesDoNotCount(): void
    {
        $failure = new NetworkException(new Request('GET', '/'), 'timeout');
        $inner = $this->scripted([500, $failure, 200, 500, 500, 429, 404, new RequestException(new Request('GET', '/'), 'bad'), 200]);
        $client = new CircuitBreakerClient($inner, $breaker = $this->breaker());

        $client->sendRequest(new Request('GET', 'https://api.test/x'));
        try {
            $client->sendRequest(new Request('GET', 'https://api.test/x'));
        } catch (NetworkException) {
        }
        $client->sendRequest(new Request('GET', 'https://api.test/x'));
        $client->sendRequest(new Request('GET', 'https://api.test/x'));
        $client->sendRequest(new Request('GET', 'https://api.test/x'));
        self::assertSame(CircuitState::Closed, $breaker->state('https://api.test:443'), 'two failures since the last success');

        self::assertSame(429, $client->sendRequest(new Request('GET', 'https://api.test/x'))->getStatusCode());
        self::assertSame(404, $client->sendRequest(new Request('GET', 'https://api.test/x'))->getStatusCode());
        try {
            $client->sendRequest(new Request('GET', 'https://api.test/x'));
        } catch (RequestException) {
        }
        self::assertSame(CircuitState::Closed, $breaker->state('https://api.test:443'), '429, 404 and a RequestException are not host failures');
    }

    public function testHalfOpenLetsOneProbeThrough(): void
    {
        $breaker = $this->breaker(failures: 1);
        $breaker->failure('geo');
        self::assertFalse($breaker->allow('geo'));

        $this->now += 30;
        self::assertSame(CircuitState::HalfOpen, $breaker->state('geo'));
        self::assertTrue($breaker->allow('geo'), 'the probe');
        self::assertFalse($breaker->allow('geo'), 'the others wait for its outcome');
        $breaker->failure('geo');
        self::assertSame(CircuitState::Open, $breaker->state('geo'), 'a failed probe opens the circuit again');

        $this->now += 30;
        self::assertTrue($breaker->allow('geo'));
        $breaker->success('geo');
        self::assertSame(CircuitState::Closed, $breaker->state('geo'));
        self::assertTrue($breaker->allow('geo'));

        $breaker->failure('geo');
        $breaker->reset('geo');
        self::assertSame(CircuitState::Closed, $breaker->state('geo'));
    }

    public function testWorkersSharingAStoreShareTheCircuit(): void
    {
        $store = new ArrayCache();
        $logger = self::logger();
        $first = $this->breaker($store, 2, $logger);
        $second = $this->breaker($store, 2, $logger);

        $first->failure('https://api.test:443');
        $second->failure('https://api.test:443');
        self::assertFalse($first->allow('https://api.test:443'));
        self::assertFalse($second->allow('https://api.test:443'));
        self::assertSame([['warning', 'Circuit https://api.test:443 opened for 30 s after 2 consecutive failures']], $logger->records);

        $store->set('circuit.' . hash('xxh128', 'junk'), 'not a state');
        self::assertSame(CircuitState::Closed, $first->state('junk'), 'an unreadable entry is a closed circuit');
    }

    public function testRetriesStopAtAnOpenCircuit(): void
    {
        $inner = $this->scripted([503, 503, 200]);
        $client = $this->retrying(new CircuitBreakerClient($inner, $this->breaker(failures: 1)));

        $this->expectException(CircuitOpenException::class);
        try {
            $client->sendRequest(new Request('GET', 'https://api.test/x'));
        } finally {
            self::assertSame(1, $inner->calls());
            self::assertSame([0.1], $this->slept, 'one wait, then the breaker refused the retry and it was not retried again');
        }
    }

    public function testCircuitBreakerConfigIsValidated(): void
    {
        foreach ([
            static fn(): CircuitBreakerConfig => new CircuitBreakerConfig(failures: 0),
            static fn(): CircuitBreakerConfig => new CircuitBreakerConfig(openFor: 0),
            static fn(): CircuitBreakerConfig => new CircuitBreakerConfig(window: 0),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertSame(CircuitState::Closed, new CircuitBreaker(new CircuitBreakerConfig(shared: false))->state('x'), 'per-worker memory without a store');
    }
}
