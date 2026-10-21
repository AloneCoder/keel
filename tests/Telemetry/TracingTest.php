<?php

declare(strict_types=1);

namespace Keel\Tests\Telemetry;

use Keel\Container\Definitions;
use Keel\Http\Middleware\RequestIdMiddleware;
use Keel\Kernel\Kernel;
use Keel\Telemetry\TracingMiddleware;
use Keel\Tests\Kernel\Fixtures\ArrayLogger;
use Keel\Tests\Kernel\Fixtures\Clock;
use Keel\Tests\Kernel\Fixtures\UserController;
use Nyholm\Psr7\ServerRequest;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(TracingMiddleware::class)]
final class TracingTest extends TestCase
{
    private InMemoryExporter $exporter;

    private function kernel(): Kernel
    {
        $this->exporter = new InMemoryExporter();
        $definitions = new Definitions();
        $definitions->set(Clock::class);
        $definitions->instance(LoggerInterface::class, new ArrayLogger());
        $definitions->instance(TracerProviderInterface::class, new TracerProvider(new SimpleSpanProcessor($this->exporter)));

        return Kernel::build($definitions, [UserController::class], [RequestIdMiddleware::class, TracingMiddleware::class]);
    }

    private function span(): ImmutableSpan
    {
        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans, 'one server span per request');
        $span = $spans[0];
        self::assertInstanceOf(ImmutableSpan::class, $span);

        return $span;
    }

    public function testServerSpanContinuesTheIncomingTraceAndCarriesHttpAttributes(): void
    {
        $kernel = $this->kernel();
        $headers = [
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
            'User-Agent' => 'keel-test/1.0',
            'X-Request-Id' => 'req-42',
        ];

        $response = $kernel->handle(new ServerRequest('GET', 'https://app.test/api/users/7?x=1', $headers, null, '1.1', ['REMOTE_ADDR' => '203.0.113.9']));

        self::assertSame(200, $response->getStatusCode());
        $span = $this->span();
        self::assertSame('GET /api/users/{id}', $span->getName(), 'named by route pattern, not by path');
        self::assertSame(SpanKind::KIND_SERVER, $span->getKind());
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $span->getTraceId());
        self::assertSame('b7ad6b7169203331', $span->getParentSpanId());
        self::assertSame([
            'http.request.method' => 'GET',
            'url.path' => '/api/users/7',
            'url.scheme' => 'https',
            'server.address' => 'app.test',
            'url.query' => 'x=1',
            'http.route' => '/api/users/{id}',
            'user_agent.original' => 'keel-test/1.0',
            'client.address' => '203.0.113.9',
            'keel.request_id' => 'req-42',
            'http.response.status_code' => 200,
        ], $span->getAttributes()->toArray());
        self::assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
    }

    public function testClientErrorsAreNotFailuresButServerErrorsAre(): void
    {
        $kernel = $this->kernel();
        $kernel->handle(new ServerRequest('GET', '/nowhere'));
        $span = $this->span();
        self::assertSame('GET', $span->getName(), 'no route: the method alone, never the raw path');
        self::assertSame(404, $span->getAttributes()->get('http.response.status_code'));
        self::assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
        self::assertSame([], $span->getEvents());

        $kernel = $this->kernel();
        $response = $kernel->handle(new ServerRequest('GET', '/api/users/boom'));
        self::assertSame(500, $response->getStatusCode());
        $span = $this->span();
        self::assertSame('GET /api/users/boom', $span->getName());
        self::assertSame(500, $span->getAttributes()->get('http.response.status_code'));
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame('Something broke', $span->getStatus()->getDescription());
        self::assertCount(1, $span->getEvents());
        self::assertSame('exception', $span->getEvents()[0]->getName());
    }

    public function testTheSpanIsOnTheRequestAndNothingStaysActiveAfterwards(): void
    {
        $kernel = $this->kernel();
        $kernel->handle(new ServerRequest('GET', '/api/users/1'));
        $kernel->handle(new ServerRequest('GET', '/api/users/2'));

        $spans = $this->exporter->getSpans();
        self::assertCount(2, $spans);
        self::assertInstanceOf(ImmutableSpan::class, $spans[0]);
        self::assertInstanceOf(ImmutableSpan::class, $spans[1]);
        self::assertNotSame($spans[0]->getTraceId(), $spans[1]->getTraceId(), 'each request is its own trace: the scope of the first was detached');
        self::assertSame('0000000000000000', $spans[1]->getParentSpanId());
        self::assertNull(TracingMiddleware::span(new ServerRequest('GET', '/')));
    }
}
