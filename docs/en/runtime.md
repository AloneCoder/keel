# Runtimes

Namespace `Keel\Runtime`. One front controller runs under php-fpm, FrankenPHP worker mode
and RoadRunner: the runtime is chosen when the process starts.

```php
// public/index.php
$kernel = Kernel::build(new Definitions(), [HelloController::class]);
exit(Runtime::run($kernel));
```

## Runtime selection

`Runtime::detect()` checks, in this order:

1. `KEEL_RUNTIME` (`fpm`, `frankenphp`, `roadrunner`) from `$_SERVER` or the environment: an explicit choice;
2. the `frankenphp_handle_request` function plus `$_SERVER['FRANKENPHP_WORKER']`: FrankenPHP worker mode;
3. `RR_MODE=http`: RoadRunner;
4. otherwise `FpmRuntime`.

| Runtime | Model | How it runs |
|---|---|---|
| `FpmRuntime` | one process per request: php-fpm, mod_php, `php -S`, FrankenPHP without worker mode | `handle()` → `emit()` → `terminate()` once |
| `FrankenPhpRuntime` | long-lived worker, requests arrive through `frankenphp_handle_request()` | loop up to `MAX_REQUESTS` (argument or `$_SERVER['MAX_REQUESTS']`); `terminate()` and `gc_collect_cycles()` after every request |
| `RoadRunnerRuntime` | long-lived worker, PSR-7 over `spiral/roadrunner-http` | loop `waitRequest()` → `handle()` → `respond()` until RoadRunner stops the worker |

`spiral/roadrunner-http` is an optional dependency; without it `RoadRunnerRuntime` fails at
start with a clear exception.

## Boot memory

A production boot with warm caches loads about 30 files and creates neither definitions nor
reflection; `Route` objects are materialized as routes match, event listeners are compiled
into the container. Under php-fpm add `opcache.preload` with the script from `keel:preload`:
otherwise every process links the trait-based classes (nyholm/psr7 messages) again, about
100 KB per process and a visible share of the first request. Measured on the `benchmarks/` app:
without preload boot takes 72 KB and 30 files, with preload 4 KB and 3 files; boot plus first
request 12.8 ms versus 2.7 ms.

## Between requests

In worker modes the same `Kernel`, container and router serve thousands of requests. The rules
the kernel follows and the application must follow too:

- `Kernel::terminate($request, $response)` runs after every request, once the response went
  out: it dispatches `ResponseSent`, runs the deferred tasks, then calls `Container::reset()`,
  so every instantiated shared service implementing `Resettable` drops its request state. The
  reset runs in `finally`, even when sending the response failed. Under php-fpm the request is
  finished for the client first (`fastcgi_finish_request()`); FrankenPHP calls it after the
  request handler returned, RoadRunner after the response went back to the server.
- Nothing request-specific lives in statics, globals or properties of services that are not
  `Resettable`. The request is passed explicitly.
- An exception while creating the request or emitting the response is logged as `critical`
  and does not stop the worker; `Kernel::handle()` itself never throws.
- `MAX_REQUESTS` is the safety net against memory leaks in third-party code: the worker exits
  and FrankenPHP/RoadRunner start a fresh one.
- `MemoryGuard` is the second net: after every request the runtimes compare the memory the
  process holds (`memory_get_usage(true)`) with `KEEL_MAX_MEMORY` (bytes or `256M`), by default
  90% of `memory_limit`; above it the worker logs a warning with the numbers and stops instead
  of dying mid-request with "Allowed memory size exhausted". `KEEL_MAX_MEMORY=0` turns it off.
  `MetricsMiddleware` exports the same number as the `process_memory_bytes` gauge, and
  `keel:doctor` reports which limits a worker has.

The `examples/hello` route `/stats` shows `request_hits` (always 1, the service is `Resettable`)
and `worker_hits` (grows in worker mode, 1 under php-fpm).

## Lifecycle events

The kernel and the runtimes dispatch PSR-14 events on the application's dispatcher (see
[Events](app.md#events-psr-14)); an event is only built when a listener applies to it, so the
ones nobody listens to cost an array lookup per request.

| Event (`Keel\Kernel\Event`) | When | Carries |
|---|---|---|
| `RequestReceived` | a request enters the pipeline, before the global middleware | `request` |
| `ExceptionRaised` | a throwable escapes the pipeline, before it is rendered | `exception`, `request`, `status` |
| `ResponseSent` | in `Kernel::terminate()`, after the response went out | `request`, `response` |
| `WorkerStarted` | a FrankenPHP or RoadRunner worker, before its first request | `runtime` |
| `WorkerStopping` | the worker loop ended | `runtime`, `requests`, `reason`: `server`, `max_requests` or `memory` |

```php
final class ErrorReporter
{
    public function __construct(private readonly Tracker $tracker) {}

    #[Listener]
    public function report(ExceptionRaised $event): void
    {
        if ($event->status >= 500) {
            $this->tracker->capture($event->exception, ['path' => $event->request->getUri()->getPath()]);
        }
    }
}
```

A `RequestReceived` listener that throws is answered like a failing handler; a failure in an
`ExceptionRaised` or `ResponseSent` listener is logged and changes nothing for the client.
`WorkerStarted` and `WorkerStopping` are not dispatched under php-fpm, where every request is a
fresh process; an exception in a `WorkerStarted` listener stops the worker before it serves.

## Work after the response

```php
#[Post('/signup')]
#[Status(201)]
public function signUp(#[FromBody] SignUp $form, DeferredTasks $deferred): array
{
    $user = $this->users->create($form);
    $deferred->defer(fn() => $this->mailer->sendWelcome($user));
    $deferred->defer(fn() => $this->crm->push($user));

    return ['id' => $user->id];
}
```

`DeferredTasks::defer()` queues a closure that `Kernel::terminate()` runs once the client has the
response: a mail, a webhook, a cache warm-up, an audit record, without a queue. Tasks run in
order; one that throws is logged and the next still runs; a task may defer more. They are not
durable (a crash or a deploy loses them) and, in worker mode, the worker takes no request until
they finish, so keep them short and use a real queue for work that must not be lost.
`TestClient` runs them before it returns the response, like a worker does.

## Load shedding

```php
// config/load_shedding.php
return new LoadSheddingConfig(
    maxInFlight: 14,          // of 16 workers: the rest answer 503 fast instead of queueing
    maxQueueTime: 2_000,      // ms the request waited in front of PHP (X-Request-Start)
    maxLoadAverage: 12.0,
    minAvailableMemory: '256M',
);
```

`LoadSheddingMiddleware` answers 503 with `Retry-After` before any work while the server is
overloaded. A quick 503 for some requests keeps the others answerable, where doing all the work
would time them all out. Every signal is off until given a threshold:

| Signal | Reads | Notes |
|---|---|---|
| `maxInFlight` | requests being handled right now by the workers that share APCu | a php-fpm pool or a FrankenPHP server; RoadRunner workers have their own APCu each (bound concurrency with the pool size there). The counter restarts every 60 seconds, so a worker killed mid-request cannot leave it stuck; without APCu only this process is counted |
| `maxQueueTime` | `X-Request-Start` or `X-Queue-Start` from the proxy: `t=<seconds>`, milliseconds or microseconds | nginx: `proxy_set_header X-Request-Start "t=${msec}";` (Heroku sets it). A client can only make its own request look old |
| `maxLoadAverage` | the one-minute load average of the host | |
| `minAvailableMemory` | the cgroup v2 limit minus its working set inside a container, `MemAvailable` otherwise | Linux; skipped where neither can be read; read at most once a second |

Paths under `except` (`/health` and `/metrics` by default) are never shed; place the middleware
after `HealthMiddleware` and `MetricsMiddleware` as well. Each shed request is logged at info
level with the reason. It is the sibling of the `MemoryGuard`: the guard recycles a worker that
grew too large, the shedding protects the server as a whole.

## Verified live

The example was run under FrankenPHP 1.12.6 (embedded PHP 8.5.8) in worker mode with
`examples/hello/Caddyfile`: one pid for every request, `request_hits` equal to 1 in every
response, `worker_hits` growing, 300 consecutive requests all answered 200, no errors in the log.

## Load

`benchmarks/frankenphp/run.sh` starts Keel and Slim 4 in FrankenPHP worker mode and hits them with `oha`.
On an Apple Silicon laptop, 8 workers, 64 connections, 10 s: Keel 8 486 requests/s, Slim 8 603,
median 5.2 ms, p99 34 ms for both. The ceiling is Caddy's HTTP stack, not the framework.

## Example configurations

- `examples/hello/Caddyfile`: `frankenphp run --config examples/hello/Caddyfile`
  (worker mode, 2 workers, `MAX_REQUESTS 1000`).
- `examples/hello/.rr.yaml`: `rr serve` from the example directory.
- php-fpm and the built-in server need no configuration beyond `root` pointing at `public/`.
