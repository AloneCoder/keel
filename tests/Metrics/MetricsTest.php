<?php

declare(strict_types=1);

namespace Keel\Tests\Metrics;

use Keel\Container\Definitions;
use Keel\Kernel\Kernel;
use Keel\Metrics\InMemoryStorage;
use Keel\Metrics\MetricsConfig;
use Keel\Metrics\MetricsMiddleware;
use Keel\Metrics\MetricsStorage;
use Keel\Metrics\Registry;
use Keel\Testing\TestClient;
use Keel\Tests\Health\Fixtures\StatusController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Registry::class)]
#[CoversClass(InMemoryStorage::class)]
#[CoversClass(MetricsConfig::class)]
#[CoversClass(MetricsMiddleware::class)]
final class MetricsTest extends TestCase
{
    public function testRendersCountersGaugesAndHistogramsInExpositionFormat(): void
    {
        $registry = new Registry(new InMemoryStorage());
        $registry
            ->counter('jobs_total', 'Jobs "done"', ['queue'])
            ->gauge('temperature', 'Room temp')
            ->histogram('latency_seconds', 'Latency', ['op'], [0.1, 1.0]);

        $registry->inc('jobs_total', ['mail']);
        $registry->inc('jobs_total', ['mail'], 2);
        $registry->inc('jobs_total', ["we\"ird\n"]);
        $registry->set('temperature', [], 21.5);
        $registry->observe('latency_seconds', ['read'], 0.05);
        $registry->observe('latency_seconds', ['read'], 0.5);
        $registry->observe('latency_seconds', ['read'], 3.0);

        $expected = <<<'TXT'
            # HELP jobs_total Jobs "done"
            # TYPE jobs_total counter
            jobs_total{queue="mail"} 3
            jobs_total{queue="we\"ird\n"} 1
            # HELP temperature Room temp
            # TYPE temperature gauge
            temperature 21.5
            # HELP latency_seconds Latency
            # TYPE latency_seconds histogram
            latency_seconds_bucket{op="read",le="0.1"} 1
            latency_seconds_bucket{op="read",le="1"} 2
            latency_seconds_bucket{op="read",le="+Inf"} 3
            latency_seconds_count{op="read"} 3
            latency_seconds_sum{op="read"} 3.55

            TXT;
        self::assertSame($expected, $registry->render());
    }

    public function testRegistryRejectsMisuse(): void
    {
        $registry = new Registry(new InMemoryStorage());
        $registry->counter('ok_total', 'x', ['a']);

        try {
            $registry->inc('ok_total', []);
            self::fail('label count is checked');
        } catch (\LogicException $e) {
            self::assertStringContainsString('expects 1 label values', $e->getMessage());
        }
        try {
            $registry->set('ok_total', ['v'], 1.0);
            self::fail('type is checked');
        } catch (\LogicException $e) {
            self::assertStringContainsString('is a counter, not a gauge', $e->getMessage());
        }
        try {
            $registry->inc('missing');
            self::fail('registration is checked');
        } catch (\LogicException $e) {
            self::assertStringContainsString('not registered', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $registry->counter('bad name', 'x');
    }

    public function testMiddlewareCountsRequestsByRouteAndServesTheEndpoint(): void
    {
        $storage = new InMemoryStorage();
        $definitions = new Definitions();
        $definitions->instance(MetricsStorage::class, $storage);
        $definitions->set(Registry::class)->argument('storage', new \Keel\Container\Ref(MetricsStorage::class));
        $definitions->instance(MetricsConfig::class, new MetricsConfig('/metrics', [1.0]));
        $client = TestClient::forKernel(Kernel::build($definitions, [StatusController::class], [MetricsMiddleware::class]));

        $client->get('/status')->assertOk();
        $client->get('/status')->assertOk();
        $client->get('/nope')->assertStatus(404);
        $client->post('/status')->assertStatus(405);

        $text = $client->get('/metrics')->assertOk()->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')->body();
        self::assertStringContainsString('http_requests_total{method="GET",route="/status",status="200"} 2', $text);
        self::assertStringContainsString('http_requests_total{method="GET",route="unmatched",status="404"} 1', $text);
        self::assertStringContainsString('http_requests_total{method="POST",route="unmatched",status="405"} 1', $text);
        self::assertStringContainsString('http_request_duration_seconds_bucket{method="GET",route="/status",le="1"} 2', $text);
        self::assertStringContainsString('http_request_duration_seconds_count{method="GET",route="/status"} 2', $text);
        self::assertStringNotContainsString('route="/metrics"', $text, 'the endpoint itself is not counted');
    }
}
