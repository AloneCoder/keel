<?php

declare(strict_types=1);

/**
 * In-process benchmark: framework overhead per request, same route, same payload, no SAPI.
 * Each framework is booted once in production mode (caches warm), then handles the same
 * PSR-7 / HttpFoundation request N times. Usage: php benchmarks/run.php [iterations]
 */

use Keel\App\App;
use Keel\Benchmarks\SymfonyKernel;
use Keel\Config\Env;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Slim\Factory\AppFactory;
use Symfony\Component\HttpFoundation\Request;

require __DIR__ . '/vendor/autoload.php';

$iterations = (int) ($argv[1] ?? 20_000);
$path = '/json/42';
$expected = '{"id":42,"name":"item 42","ok":true}';

$frameworks = [
    'Keel ' . \Keel\Keel::VERSION => static function () use ($path): array {
        $boot = hrtime(true);
        $app = App::boot(__DIR__ . '/keel-app', new Env([]));
        $bootTime = hrtime(true) - $boot;
        $request = new ServerRequest('GET', $path);

        return [$bootTime, static fn(): string => (string) $app->kernel->handle($request)->getBody()];
    },
    'Slim ' . \Slim\App::VERSION => static function () use ($path): array {
        $boot = hrtime(true);
        $psr17 = new Psr17Factory();
        AppFactory::setResponseFactory($psr17);
        $app = AppFactory::create();
        $app->get('/json/{id:\d+}', static function ($request, $response, array $args) {
            $response->getBody()->write(json_encode(['id' => (int) $args['id'], 'name' => 'item ' . $args['id'], 'ok' => true]));

            return $response->withHeader('Content-Type', 'application/json');
        });
        $app->get('/plain', static function ($request, $response) {
            $response->getBody()->write('Hello');

            return $response;
        });
        $bootTime = hrtime(true) - $boot;
        $request = new ServerRequest('GET', $path);

        return [$bootTime, static fn(): string => (string) $app->handle($request)->getBody()];
    },
    'Symfony ' . \Symfony\Component\HttpKernel\Kernel::VERSION . ' MicroKernel' => static function () use ($path): array {
        $boot = hrtime(true);
        $kernel = new SymfonyKernel('prod', false);
        $kernel->boot();
        $bootTime = hrtime(true) - $boot;
        $request = Request::create($path);

        return [$bootTime, static function () use ($kernel, $request): string {
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            return (string) $response->getContent();
        }];
    },
];

printf("PHP %s, %d iterations of GET %s, in-process (no SAPI), opcache %s\n\n", \PHP_VERSION, $iterations, $path, function_exists('opcache_get_status') && opcache_get_status(false) !== false ? 'on' : 'off');
printf("%-32s %12s %12s %14s %12s\n", 'Framework', 'boot (ms)', 'µs/request', 'requests/s', 'boot MB');

foreach ($frameworks as $name => $factory) {
    gc_collect_cycles();
    $before = memory_get_usage();
    [$bootTime, $handle] = $factory();
    $bootMemory = memory_get_usage() - $before;
    $body = $handle();
    if ($body !== $expected) {
        throw new RuntimeException($name . ' returned unexpected body: ' . $body);
    }
    for ($i = 0; $i < 500; $i++) {
        $handle();
    }

    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $handle();
    }
    $elapsed = hrtime(true) - $start;
    $perRequest = $elapsed / $iterations / 1_000;

    printf("%-32s %12.1f %12.1f %14s %12.2f\n", $name, $bootTime / 1_000_000, $perRequest, number_format(1_000_000 / $perRequest), $bootMemory / 1_048_576);
}
