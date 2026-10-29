<?php

declare(strict_types=1);

namespace Keel\Telemetry;

use Keel\Http\Exception\HttpException;
use Keel\Http\Middleware\RequestIdMiddleware;
use Keel\Http\Middleware\TrustedProxiesMiddleware;
use Keel\Keel;
use Keel\Routing\RouteMatch;
use Keel\Routing\Router;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * One OpenTelemetry server span per request, named "METHOD /route/{id}" with the HTTP semantic
 * attributes, continuing the trace of an incoming traceparent header (W3C Trace Context by
 * default). The span is active while the handler runs, so spans the application starts
 * through the tracer become its children, and it is on the request as the `otel.span`
 * attribute (span()) for events and extra attributes. 5xx answers and exceptions mark it as
 * an error, and the exception is recorded.
 *
 * A method outside the standard ones is recorded as "_OTHER" (the span is then named "HTTP"), so
 * clients cannot grow the set of span names; the values of query parameters listed in
 * SENSITIVE_QUERY (signed-URL signatures, tokens) are written as "REDACTED".
 *
 * Needs open-telemetry/api; register a TracerProviderInterface (from open-telemetry/sdk, or the
 * no-op one) in the container: composer require open-telemetry/sdk.
 */
final class TracingMiddleware implements MiddlewareInterface
{
    public const string SPAN = 'otel.span';

    /** Query parameters (compared case-insensitively) whose values never reach the tracing backend. */
    public const array SENSITIVE_QUERY = [
        'signature', 'sig', 'x-goog-signature', 'awsaccesskeyid', 'token', 'access_token', 'refresh_token',
        'id_token', 'code', 'password', 'secret', 'api_key', 'apikey', 'key',
    ];

    /** Methods recorded as themselves (OpenTelemetry HTTP semantic conventions). */
    private const array METHODS = ['CONNECT', 'DELETE', 'GET', 'HEAD', 'OPTIONS', 'PATCH', 'POST', 'PUT', 'TRACE'];

    private readonly TracerInterface $tracer;

    private readonly TextMapPropagatorInterface $propagator;

    public function __construct(
        TracerProviderInterface $tracerProvider,
        ?TextMapPropagatorInterface $propagator = null,
        private readonly ?Router $router = null,
    ) {
        $this->tracer = $tracerProvider->getTracer('keel', Keel::VERSION);
        $this->propagator = $propagator ?? TraceContextPropagator::getInstance();
    }

    public static function span(ServerRequestInterface $request): ?SpanInterface
    {
        $span = $request->getAttribute(self::SPAN);

        return $span instanceof SpanInterface ? $span : null;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $carrier = [];
        foreach ($request->getHeaders() as $name => $values) {
            $carrier[strtolower($name)] = implode(', ', $values);
        }

        $method = in_array($request->getMethod(), self::METHODS, true) ? $request->getMethod() : '_OTHER';
        $route = $this->route($request);
        $uri = $request->getUri();
        $attributes = [
            'http.request.method' => $method,
            'url.path' => $uri->getPath(),
            'url.scheme' => $uri->getScheme(),
            'server.address' => $uri->getHost(),
        ];
        if ($method === '_OTHER') {
            $attributes['http.request.method_original'] = $request->getMethod();
        }
        if ($uri->getQuery() !== '') {
            $attributes['url.query'] = self::redacted($uri->getQuery());
        }
        if ($route !== null) {
            $attributes['http.route'] = $route;
        }
        if ($request->hasHeader('User-Agent')) {
            $attributes['user_agent.original'] = $request->getHeaderLine('User-Agent');
        }
        $client = TrustedProxiesMiddleware::clientIp($request);
        if ($client !== null) {
            $attributes['client.address'] = $client;
        }
        $requestId = RequestIdMiddleware::of($request);
        if ($requestId !== null) {
            $attributes['keel.request_id'] = $requestId;
        }

        $name = ($method === '_OTHER' ? 'HTTP' : $method) . ($route === null ? '' : ' ' . $route);
        $span = $this->tracer->spanBuilder($name)
            ->setParent($this->propagator->extract($carrier))
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttributes($attributes)
            ->startSpan();
        $scope = $span->activate();

        try {
            $response = $handler->handle($request->withAttribute(self::SPAN, $span));
            $span->setAttribute('http.response.status_code', $response->getStatusCode());
            if ($response->getStatusCode() >= 500) {
                $span->setStatus(StatusCode::STATUS_ERROR);
            }

            return $response;
        } catch (HttpException $exception) {
            // Answered by ExceptionHandlerMiddleware with this status: a client error is not a failed span.
            $span->setAttribute('http.response.status_code', $exception->status);
            if ($exception->status >= 500) {
                $span->recordException($exception);
                $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
            }

            throw $exception;
        } catch (\Throwable $exception) {
            $span->setAttribute('http.response.status_code', 500);
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());

            throw $exception;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * The route pattern: from the routing result when routing already ran, else a lookup, so a
     * global middleware still names spans by route instead of by concrete path.
     */
    /**
     * The query with the values of SENSITIVE_QUERY parameters replaced by "REDACTED".
     */
    private static function redacted(string $query): string
    {
        $pairs = explode('&', $query);
        foreach ($pairs as $i => $pair) {
            [$name, $value] = explode('=', $pair, 2) + [1 => ''];
            if ($value !== '' && in_array(strtolower(urldecode($name)), self::SENSITIVE_QUERY, true)) {
                $pairs[$i] = $name . '=REDACTED';
            }
        }

        return implode('&', $pairs);
    }

    private function route(ServerRequestInterface $request): ?string
    {
        $match = $request->getAttribute(RouteMatch::class);
        if ($match instanceof RouteMatch) {
            return $match->route->template();
        }

        return $this->router?->match($request->getMethod(), $request->getUri()->getPath())->match?->route->template();
    }
}
