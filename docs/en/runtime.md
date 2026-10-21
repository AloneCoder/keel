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

- `Kernel::terminate()` calls `Container::reset()` after every request: every instantiated
  shared service implementing `Resettable` drops its request state. This runs in `finally`,
  even when sending the response failed.
- Nothing request-specific lives in statics, globals or properties of services that are not
  `Resettable`. The request is passed explicitly.
- An exception while creating the request or emitting the response is logged as `critical`
  and does not stop the worker; `Kernel::handle()` itself never throws.
- `MAX_REQUESTS` is the safety net against memory leaks in third-party code: the worker exits
  and FrankenPHP/RoadRunner start a fresh one.

The `examples/hello` route `/stats` shows `request_hits` (always 1, the service is `Resettable`)
and `worker_hits` (grows in worker mode, 1 under php-fpm).

## Verified live

The example was run under FrankenPHP 1.12.6 (embedded PHP 8.5.8) in worker mode with
`examples/hello/Caddyfile`: one pid for every request, `request_hits` equal to 1 in every
response, `worker_hits` growing, 300 consecutive requests all answered 200, no errors in the log.

## Example configurations

- `examples/hello/Caddyfile`: `frankenphp run --config examples/hello/Caddyfile`
  (worker mode, 2 workers, `MAX_REQUESTS 1000`).
- `examples/hello/.rr.yaml`: `rr serve` from the example directory.
- php-fpm and the built-in server need no configuration beyond `root` pointing at `public/`.
