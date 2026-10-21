<?php

declare(strict_types=1);

namespace Keel\Tests\Health;

use Keel\Container\Definitions;
use Keel\Health\HealthCheck;
use Keel\Health\HealthConfig;
use Keel\Health\HealthMiddleware;
use Keel\Health\HealthResult;
use Keel\Health\HealthStatus;
use Keel\Kernel\Kernel;
use Keel\Testing\TestClient;
use Keel\Tests\Health\Fixtures\DiskCheck;
use Keel\Tests\Health\Fixtures\QueueCheck;
use Keel\Tests\Health\Fixtures\StatusController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

#[CoversClass(HealthMiddleware::class)]
#[CoversClass(HealthResult::class)]
#[CoversClass(HealthConfig::class)]
#[CoversClass(HealthStatus::class)]
final class HealthTest extends TestCase
{
    /**
     * @param list<class-string<HealthCheck>> $checks
     */
    private function client(array $checks, ?HealthConfig $config = null, ?LoggerInterface $logger = null): TestClient
    {
        $definitions = new Definitions();
        foreach ($checks as $check) {
            $definitions->set($check)->tag(HealthCheck::TAG);
        }
        if ($config !== null) {
            $definitions->instance(HealthConfig::class, $config);
        }
        if ($logger !== null) {
            $definitions->instance(LoggerInterface::class, $logger);
        }

        return TestClient::forKernel(Kernel::build($definitions, [StatusController::class], [HealthMiddleware::class]));
    }

    public function testReadinessReportsEveryCheck(): void
    {
        $client = $this->client([DiskCheck::class, QueueCheck::class]);

        $middleware = new HealthMiddleware(new \Keel\Http\Responder(new \Nyholm\Psr7\Factory\Psr17Factory(), new \Nyholm\Psr7\Factory\Psr17Factory()), new \ArrayIterator([new DiskCheck(), new QueueCheck()]));
        self::assertSame('degraded', $middleware->report()['status']);
        self::assertSame(['disk', 'queue'], array_keys($middleware->report()['checks']));

        $client->get('/health')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store')
            ->assertJson(['status' => 'degraded', 'checks' => [
                'disk' => ['status' => 'up', 'free' => 42],
                'queue' => ['status' => 'degraded', 'detail' => 'lag 5s'],
            ]]);
    }

    public function testADownCheckOrAThrowingCheckIs503(): void
    {
        QueueCheck::$result = HealthResult::down('connection refused');
        try {
            $this->client([QueueCheck::class, DiskCheck::class])->get('/health')->assertStatus(503)->assertJsonPath('status', 'down')->assertJsonPath('checks.queue.detail', 'connection refused');
        } finally {
            QueueCheck::$result = null;
        }

        QueueCheck::$throw = true;
        try {
            $this->client([QueueCheck::class])->get('/health')->assertStatus(503)->assertJsonPath('checks.queue.detail', HealthMiddleware::HIDDEN_DETAIL);
        } finally {
            QueueCheck::$throw = false;
        }
    }

    public function testExceptionMessagesAreLoggedAndExposedOnlyWhenConfigured(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{0: string, 1: string, 2: array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [is_string($level) ? $level : get_debug_type($level), (string) $message, $context];
            }
        };

        QueueCheck::$throw = true;
        try {
            $this->client([QueueCheck::class], logger: $logger)->get('/health')
                ->assertStatus(503)
                ->assertJsonPath('checks.queue.detail', 'check failed');
            self::assertCount(1, $logger->records);
            self::assertSame('error', $logger->records[0][0]);
            self::assertSame('boom', $logger->records[0][2]['message'] ?? null);
            self::assertInstanceOf(\RuntimeException::class, $logger->records[0][2]['exception'] ?? null);

            $this->client([QueueCheck::class], new HealthConfig(exposeDetails: true))->get('/health')
                ->assertStatus(503)
                ->assertJsonPath('checks.queue.detail', 'boom');
        } finally {
            QueueCheck::$throw = false;
        }

        // A result that carries the exception behaves like a thrown one.
        QueueCheck::$result = HealthResult::down('SQLSTATE[HY000] [1045] Access denied for user', exception: new \RuntimeException('access denied'));
        try {
            $this->client([QueueCheck::class], logger: $logger)->get('/health')->assertStatus(503)->assertJsonPath('checks.queue.detail', 'check failed');
            self::assertCount(2, $logger->records);
            $this->client([QueueCheck::class], new HealthConfig(exposeDetails: true))->get('/health')->assertJsonPath('checks.queue.detail', 'SQLSTATE[HY000] [1045] Access denied for user');
        } finally {
            QueueCheck::$result = null;
        }
    }

    public function testLivenessRunsNothingAndOtherPathsPassThrough(): void
    {
        QueueCheck::$throw = true;
        try {
            $client = $this->client([QueueCheck::class]);
            $client->get('/health/live')->assertOk()->assertJson(['status' => 'up']);
            $client->get('/status')->assertOk()->assertJson(['app' => 'ok']);
            $client->post('/health')->assertStatus(404);
        } finally {
            QueueCheck::$throw = false;
        }
    }

    public function testPathsAreConfigurableAndNullDisables(): void
    {
        $client = $this->client([], new HealthConfig('/ready', null));
        $client->get('/ready')->assertOk()->assertJson(['status' => 'up', 'checks' => []]);
        $client->get('/health/live')->assertStatus(404);
        $client->get('/health')->assertStatus(404);
    }
}
