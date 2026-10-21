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

#[CoversClass(HealthMiddleware::class)]
#[CoversClass(HealthResult::class)]
#[CoversClass(HealthConfig::class)]
#[CoversClass(HealthStatus::class)]
final class HealthTest extends TestCase
{
    /**
     * @param list<class-string<HealthCheck>> $checks
     */
    private function client(array $checks, ?HealthConfig $config = null): TestClient
    {
        $definitions = new Definitions();
        foreach ($checks as $check) {
            $definitions->set($check)->tag(HealthCheck::TAG);
        }
        if ($config !== null) {
            $definitions->instance(HealthConfig::class, $config);
        }

        return TestClient::forKernel(Kernel::build($definitions, [StatusController::class], [HealthMiddleware::class]));
    }

    public function testReadinessReportsEveryCheck(): void
    {
        $client = $this->client([DiskCheck::class, QueueCheck::class]);

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
            $this->client([QueueCheck::class])->get('/health')->assertStatus(503)->assertJsonPath('checks.queue.detail', 'connection refused');
        } finally {
            QueueCheck::$result = null;
        }

        QueueCheck::$throw = true;
        try {
            $this->client([QueueCheck::class])->get('/health')->assertStatus(503)->assertJsonPath('checks.queue.detail', 'boom');
        } finally {
            QueueCheck::$throw = false;
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
