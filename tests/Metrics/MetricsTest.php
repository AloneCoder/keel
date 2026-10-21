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
            ->counter('jobs_total', "Jobs \\ \"done\"\nnow", ['queue'])
            ->gauge('temperature', 'Room temp')
            ->histogram('latency_seconds', 'Latency', ['op'], [1.0, 0.1]);

        $registry->inc('jobs_total', ['mail']);
        $registry->inc('jobs_total', ['mail'], 2);
        $registry->inc('jobs_total', ["we\"ird\n"]);
        $registry->set('temperature', [], 21.5);
        $registry->observe('latency_seconds', ['read'], 0.05);
        $registry->observe('latency_seconds', ['read'], 1.0);
        $registry->observe('latency_seconds', ['read'], 3.0);

        $expected = <<<'TXT'
            # HELP jobs_total Jobs \\ "done"\nnow
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
            latency_seconds_sum{op="read"} 4.05

            TXT;
        self::assertSame($expected, $registry->render());
    }

    public function testHistogramBucketsAreRenderedInOrderWithZerosAndDistinctBounds(): void
    {
        $registry = new Registry(new InMemoryStorage());
        $registry->histogram('h', 'x', ['m'], [0.1, 0.5, 1.0]);
        $registry->observe('h', ['GET'], 0.7);
        $registry->observe('h', ['GET'], 0.05);
        $registry->observe('h', ['POST'], 5.0);

        $expected = <<<'TXT'
            # HELP h x
            # TYPE h histogram
            h_bucket{m="GET",le="0.1"} 1
            h_bucket{m="GET",le="0.5"} 1
            h_bucket{m="GET",le="1"} 2
            h_bucket{m="GET",le="+Inf"} 2
            h_count{m="GET"} 2
            h_sum{m="GET"} 0.75
            h_bucket{m="POST",le="0.1"} 0
            h_bucket{m="POST",le="0.5"} 0
            h_bucket{m="POST",le="1"} 0
            h_bucket{m="POST",le="+Inf"} 1
            h_count{m="POST"} 1
            h_sum{m="POST"} 5

            TXT;
        self::assertSame($expected, $registry->render(), 'storage order does not leak into the exposition; empty buckets are listed');

        $small = new Registry(new InMemoryStorage());
        $small->histogram('tiny', 'x', [], [0.0000001, 0.0000002, 2.5]);
        $small->observe('tiny', [], 0.00000015);
        $text = $small->render();
        self::assertStringContainsString('tiny_bucket{le="1.0e-7"} 0', $text);
        self::assertStringContainsString('tiny_bucket{le="2.0e-7"} 1', $text);
        self::assertStringContainsString('tiny_bucket{le="2.5"} 1', $text);
    }

    public function testLabelValuesCannotBreakTheExpositionAndHugeValuesClamp(): void
    {
        $registry = new Registry(new InMemoryStorage());
        $registry->counter('c', 'x', ['m'])->gauge('g', 'x');
        $registry->inc('c', ["GET\x1f+Inf"]);
        self::assertStringContainsString("\nc{m=\"GET+Inf\"} 1\n", $registry->render(), 'the internal separator is stripped from label values');

        $registry->set('g', [], 1.0e300);
        self::assertMatchesRegularExpression('/\ng 9223372036854\.\d+\n/', $registry->render(), 'out-of-range gauges clamp instead of overflowing');
        $registry->set('g', [], -1.0e300);
        self::assertMatchesRegularExpression('/\ng -9223372036854\.\d+\n/', $registry->render());
        $registry->set('g', [], \NAN);
        self::assertStringContainsString("\ng 0\n", $registry->render());
    }

    public function testValuesAreRoundedToMicroseconds(): void
    {
        $registry = new Registry(new InMemoryStorage());
        $registry->gauge('g', 'x')->histogram('h', 'x', [], [1.0]);

        $registry->set('g', [], 0.0000012);
        self::assertStringContainsString("\ng 0.000001\n", $registry->render());
        $registry->set('g', [], 0.0000018);
        self::assertStringContainsString("\ng 0.000002\n", $registry->render());

        $registry->observe('h', [], 0.0000012);
        $registry->observe('h', [], 0.0000018);
        self::assertStringContainsString('h_sum 0.000003', $registry->render());
    }

    public function testDefaultStorageWorksWithoutApcu(): void
    {
        $registry = new Registry();
        $registry->counter('c', 'x');
        $registry->inc('c');
        self::assertStringContainsString("\nc 1\n", $registry->render());
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
        $definitions->instance(MetricsConfig::class, new MetricsConfig('/stats', [0.5]));
        $client = TestClient::forKernel(Kernel::build($definitions, [StatusController::class], [MetricsMiddleware::class]));

        $client->get('/status')->assertOk();
        $client->get('/status')->assertOk();
        $client->get('/nope')->assertStatus(404);
        $client->post('/status')->assertStatus(405);
        $client->request('PURGE', '/status')->assertStatus(405);
        $client->request("GET\x1fsum", '/status')->assertStatus(405);

        $client->get('/metrics')->assertStatus(404);
        $text = $client->get('/stats')->assertOk()->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')->body();
        self::assertNotSame([], $storage->all(), 'samples went to the injected storage');
        self::assertStringContainsString('http_requests_total{method="GET",route="/status",status="200"} 2', $text);
        self::assertMatchesRegularExpression('/^process_memory_bytes [1-9]\d*$/m', $text, 'the gauge holds the memory of the process that answered');
        self::assertStringContainsString('http_requests_total{method="GET",route="unmatched",status="404"} 2', $text);
        self::assertStringContainsString('http_requests_total{method="POST",route="unmatched",status="405"} 1', $text);
        self::assertStringContainsString('http_requests_total{method="OTHER",route="unmatched",status="405"} 2', $text, 'unknown methods share one label value');
        self::assertStringNotContainsString('PURGE', $text);
        self::assertStringContainsString('http_request_duration_seconds_bucket{method="GET",route="/status",le="0.5"} 2', $text);
        self::assertStringNotContainsString('le="0.005"', $text, 'configured buckets replace the defaults');
        self::assertStringContainsString('http_request_duration_seconds_count{method="GET",route="/status"} 2', $text);
        self::assertStringNotContainsString('route="/stats"', $text, 'the endpoint itself is not counted');
    }
}
