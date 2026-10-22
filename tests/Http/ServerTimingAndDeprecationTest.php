<?php

declare(strict_types=1);

namespace Keel\Tests\Http;

use Keel\Container\Definitions;
use Keel\Http\Attribute\Deprecated;
use Keel\Http\Middleware\DeprecationMiddleware;
use Keel\Http\Middleware\ServerTimingConfig;
use Keel\Http\Middleware\ServerTimingMiddleware;
use Keel\Http\ServerTiming;
use Keel\Kernel\Kernel;
use Keel\OpenApi\Generator;
use Keel\Routing\Router;
use Keel\Testing\TestClient;
use Keel\Tests\Http\Fixtures\ModernController;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServerTiming::class)]
#[CoversClass(ServerTimingMiddleware::class)]
#[CoversClass(ServerTimingConfig::class)]
#[CoversClass(Deprecated::class)]
#[CoversClass(DeprecationMiddleware::class)]
#[CoversClass(Generator::class)]
final class ServerTimingAndDeprecationTest extends TestCase
{
    public function testServerTimingCollector(): void
    {
        $timing = new ServerTiming();
        $timing->start('db', 'Database');
        $first = $timing->stop('db');
        $timing->start('db');
        $timing->stop('db');
        self::assertGreaterThanOrEqual($first, $timing->metrics()['db']['duration'], 'stopping the same name again adds up');
        self::assertSame('Database', $timing->metrics()['db']['description']);

        $timing->add('cache', 1.23456, 'say "hi"' . "\n");
        $timing->add('miss');
        self::assertStringEndsWith('cache;dur=1.235;desc="say \"hi\"", miss', $timing->header());
        self::assertSame('x', ServerTiming::entry('x', null));
        self::assertNull(ServerTiming::of(new ServerRequest('GET', '/')));

        try {
            $timing->measure('boom', static fn() => throw new \RuntimeException('fail'));
        } catch (\RuntimeException) {
        }
        self::assertArrayHasKey('boom', $timing->metrics(), 'measured even when the work throws');

        foreach ([static fn() => $timing->add('bad name'), static fn() => $timing->add('neg', -1.0), static fn() => $timing->stop('never')] as $case) {
            try {
                $case();
                self::fail('accepted');
            } catch (\InvalidArgumentException|\LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testMiddlewareReportsTotalAndHandlerMetrics(): void
    {
        $definitions = new Definitions();
        $definitions->instance(ServerTimingConfig::class, new ServerTimingConfig(responseTimeHeader: 'X-Response-Time', timingAllowOrigin: '*'));
        $client = TestClient::forKernel(Kernel::build($definitions, [ModernController::class], [ServerTimingMiddleware::class]));

        $response = $client->get('/timed')->assertOk()->assertJson(['answer' => 42]);
        self::assertMatchesRegularExpression('/^db;dur=[\d.]+;desc="Lookup", cache-miss, total;dur=[\d.]+$/', $response->header('Server-Timing'));
        self::assertMatchesRegularExpression('/^\d+\.\d{3}ms$/', $response->header('X-Response-Time'));
        $response->assertHeader('Timing-Allow-Origin', '*');

        $quiet = TestClient::forKernel(Kernel::build(new Definitions(), [ModernController::class], [[ServerTimingMiddleware::class, ['config' => new ServerTimingConfig(total: null, metrics: false)]]]));
        $quiet->get('/timed')->assertOk()->assertHeaderMissing('Server-Timing')->assertHeaderMissing('X-Response-Time');

        $this->expectException(\InvalidArgumentException::class);
        new ServerTimingConfig(responseTimeHeader: 'Bad Header');
    }

    public function testDeprecationHeaders(): void
    {
        $client = TestClient::forKernel(Kernel::build(new Definitions(), [ModernController::class]));

        $response = $client->get('/v1/users')->assertOk();
        $response->assertHeader('Deprecation', '@' . gmmktime(0, 0, 0, 7, 1, 2026));
        $response->assertHeader('Sunset', 'Fri, 01 Jan 2027 00:00:00 GMT');
        self::assertSame(
            ['<https://docs.test/v2>; rel="deprecation"; type="text/html"', '<https://api.test/v2/users>; rel="successor-version"'],
            $response->response->getHeader('Link'),
        );

        $client->get('/v1/old')->assertOk()->assertHeader('Deprecation', '@0')->assertHeaderMissing('Sunset')->assertHeaderMissing('Link');
        $client->get('/timed')->assertOk()->assertHeaderMissing('Deprecation');
    }

    public function testDeprecatedAttributeValidation(): void
    {
        self::assertSame(['since' => null, 'sunset' => null, 'link' => null, 'successor' => null], new Deprecated()->value());
        self::assertSame(Deprecated::KEY, new Deprecated()->key());
        self::assertSame([DeprecationMiddleware::class], new Deprecated()->middleware());

        foreach ([
            static fn() => new Deprecated(since: 'not a date'),
            static fn() => new Deprecated(since: '2027-01-01', sunset: '2026-01-01'),
            static fn() => new Deprecated(link: 'https://x.test/a b'),
            static fn() => new Deprecated(successor: '<https://x.test>'),
        ] as $i => $case) {
            try {
                $case();
                self::fail('case ' . $i . ' accepted');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testOpenApiMarksDeprecatedOperations(): void
    {
        $document = new Generator(Router::fromControllers(ModernController::class))->generate();
        $paths = (array) $document['paths'];
        $operation = static function (string $path) use ($paths): array {
            $item = $paths[$path] ?? null;
            self::assertIsArray($item);
            self::assertIsArray($item['get']);

            return $item['get'];
        };
        self::assertTrue($operation('/v1/users')['deprecated']);
        self::assertSame('2027-01-01T00:00:00Z', $operation('/v1/users')['x-sunset']);
        self::assertTrue($operation('/v1/old')['deprecated']);
        self::assertArrayNotHasKey('x-sunset', $operation('/v1/old'));
        self::assertArrayNotHasKey('deprecated', $operation('/timed'));
    }
}
