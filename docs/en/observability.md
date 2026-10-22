# Health and metrics

Namespaces `Keel\Health` and `Keel\Metrics`. Both are global middleware that answer their own
paths before routing and need no controller.

Because they answer before routing, `/health`, `/metrics` (and `/openapi.json`, `/docs` from
[OpenAPI](openapi.md)) cannot be protected by `#[Auth]` and shadow any application route on the
same path. Keep them off the public internet: restrict them in the reverse proxy or firewall, or
change the paths in the configs.

## Health checks

```php
#[Tag(HealthCheck::TAG)]
final class DatabaseCheck implements HealthCheck
{
    public function __construct(private readonly \PDO $pdo) {}

    public function name(): string { return 'database'; }

    public function check(): HealthResult
    {
        $started = hrtime(true);
        try {
            $this->pdo->query('SELECT 1');
        } catch (\PDOException $e) {
            return HealthResult::down($e->getMessage(), exception: $e);
        }
        $ms = (hrtime(true) - $started) / 1e6;

        return $ms > 200 ? HealthResult::degraded('slow', ['ms' => $ms]) : HealthResult::up(data: ['ms' => $ms]);
    }
}
```

Tagged `keel.health`, the class is registered by discovery and injected into `HealthMiddleware`
(`HttpConfig`). Two endpoints, paths in `HealthConfig` (`config/health.php`), `null` disables:

- `/health` (readiness): runs every check, answers `{"status": "up"|"degraded"|"down", "checks": {...}}`
  with 200, or 503 when any check is down. A check that throws is reported as down.
  `Cache-Control: no-store`.
- `/health/live` (liveness): `{"status": "up"}` without running anything, as long as the worker answers.

Exception messages stay out of the report: a thrown exception, or a `HealthResult::down()` given
its `exception`, shows `"detail": "check failed"` and the exception is logged through the
`LoggerInterface` when one is registered. Connection errors name hosts, users and paths, and the
endpoint is usually reachable without credentials. `new HealthConfig(exposeDetails: true)` puts
the messages back, for development. Details you write yourself (`down('queue lag 5s')`) are
always shown.

Checks receive their dependencies from the container when the middleware is built, so take a
connection lazily (a closure, or the container) if opening it can fail: `PdoCheck` does, which is
why an unreachable database is a `down` entry in the report rather than a 500 for every request.

Point Kubernetes probes, load balancers and uptime monitors at them; keep readiness checks cheap,
they run on every probe.

## Prometheus metrics

`MetricsMiddleware` (`HttpConfig`) records two metrics for every request and serves the text
exposition at `/metrics` (`MetricsConfig`, `config/metrics.php`):

```
http_requests_total{method="GET",route="/users/{id}",status="200"} 42
http_request_duration_seconds_bucket{method="GET",route="/users/{id}",le="0.01"} 40
http_request_duration_seconds_count{method="GET",route="/users/{id}"} 42
http_request_duration_seconds_sum{method="GET",route="/users/{id}"} 0.3123
process_memory_bytes 35651584
```

The `route` label is the route pattern, so cardinality stays bounded; requests matching no route
are labelled `unmatched`. The `method` label is one of `GET HEAD POST PUT PATCH DELETE OPTIONS`
(`MetricsMiddleware::METHODS`); any other method is recorded as `OTHER`, so a client cannot grow
the label set by inventing verbs. Because the middleware runs before routing, the pattern comes
from a second match against the compiled tables, a hash lookup for static routes. Latency buckets
are configurable (`MetricsConfig::$buckets`, seconds); the exposition lists every configured
bucket in ascending order, with `0` for buckets nothing has reached yet, then `+Inf`, `_count`
and `_sum`.

### Your own metrics

`Registry` is a shared service: register metrics once (in a provider or in the service that
uses them) and record from anywhere:

```php
final class OrderService
{
    public function __construct(private readonly Registry $metrics)
    {
        $metrics->counter('orders_total', 'Orders placed', ['channel'])
            ->histogram('order_value_eur', 'Order value', [], [10, 50, 100, 500]);
    }

    public function place(Order $order): void
    {
        $this->metrics->inc('orders_total', [$order->channel]);
        $this->metrics->observe('order_value_eur', [], $order->total);
    }
}
```

Counters (`inc`), gauges (`set`) and histograms (`observe`) with labels; `has()` tells whether a
metric is registered, `render()` returns the exposition.

### Storage

Samples live in a `MetricsStorage`: APCu when the extension is loaded, so every php-fpm child or
FrankenPHP thread of a server adds to the same numbers, otherwise the memory of the current
process (one worker's view). Values are integers internally; gauges and histogram sums are scaled
by 10⁶, which keeps APCu's atomic increments usable for durations and caps a single gauge or sum
at about ±9.2·10¹² (larger values are clamped). `Keel\Metrics\RedisStorage` aggregates the whole
fleet in one Redis hash (`RedisConfig(use: ['metrics'])`, see [Cache](cache.md#redis)); register
an implementation under `MetricsStorage` for anything else.

## Tracing (OpenTelemetry)

`Keel\Telemetry\TracingMiddleware` starts one server span per request: named `GET /users/{id}`
(the route pattern, the method alone when nothing matched), kind `SERVER`, with the HTTP semantic
attributes (`http.request.method`, `url.path`, `url.query`, `url.scheme`, `server.address`,
`http.route`, `user_agent.original`, `client.address`, `keel.request_id`,
`http.response.status_code`). An incoming `traceparent` header continues the caller's trace
(W3C Trace Context; pass another `TextMapPropagatorInterface` to change that). A 5xx answer or an
exception marks the span as an error and records the exception; a 404 or 400 does not. The span is
active while the handler runs, so spans you start through the tracer become its children, and
`TracingMiddleware::span($request)` returns it for events and attributes of your own.

It needs `open-telemetry/api` and a `TracerProviderInterface` in the container, which is where
the SDK and the exporter live (`composer require open-telemetry/sdk` plus an exporter such as
`open-telemetry/exporter-otlp`):

```php
// config/services.php, a provider
$definitions->instance(TracerProviderInterface::class, TracerProvider::builder()
    ->addSpanProcessor(new BatchSpanProcessor($exporter, ClockFactory::getDefault()))
    ->build());

// config/http.php
return new HttpConfig([RequestIdMiddleware::class, TracingMiddleware::class, /* ... */]);
```

Nothing of this is loaded unless the middleware is registered, so an application without tracing
pays nothing.

## Request ids and logs

`RequestIdMiddleware` gives every request an id (response header `X-Request-Id`, request
attribute `request_id`); include it in log context to correlate. The built-in `StreamLogger`
writes JSON lines to stderr, which FrankenPHP and RoadRunner collect; see
[Application](app.md#logging).
