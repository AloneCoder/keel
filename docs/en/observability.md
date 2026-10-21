# Health and metrics

Namespaces `Keel\Health` and `Keel\Metrics`. Both are global middleware that answer their own
paths before routing and need no controller.

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
            return HealthResult::down($e->getMessage());
        }
        $ms = (hrtime(true) - $started) / 1e6;

        return $ms > 200 ? HealthResult::degraded('slow', ['ms' => $ms]) : HealthResult::up(data: ['ms' => $ms]);
    }
}
```

Tagged `keel.health`, the class is registered by discovery and injected into `HealthMiddleware`
(`HttpConfig`). Two endpoints, paths in `HealthConfig` (`config/health.php`), `null` disables:

- `/health` (readiness): runs every check, answers `{"status": "up"|"degraded"|"down", "checks": {...}}`
  with 200, or 503 when any check is down. A check that throws is reported as down with the
  exception message. `Cache-Control: no-store`.
- `/health/live` (liveness): `{"status": "up"}` without running anything, as long as the worker answers.

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
```

The `route` label is the route pattern, so cardinality stays bounded; requests matching no route
are labelled `unmatched`. Because the middleware runs before routing, the pattern comes from a
second match against the compiled tables, a hash lookup for static routes. Latency buckets are
configurable (`MetricsConfig::$buckets`, seconds).

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
by 10⁶, which keeps APCu's atomic increments usable for durations. Register an implementation
under `MetricsStorage` for anything else.

## Request ids and logs

`RequestIdMiddleware` gives every request an id (response header `X-Request-Id`, request
attribute `request_id`); include it in log context to correlate. The built-in `StreamLogger`
writes JSON lines to stderr, which FrankenPHP and RoadRunner collect; see
[Application](app.md#logging).
