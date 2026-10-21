<?php

declare(strict_types=1);

/**
 * Memory profile: boot cost per stage, steady-state per-request allocations and request peak.
 * Usage: php -d opcache.enable_cli=1 benchmarks/memory.php
 */

use Keel\App\App;
use Keel\Config\Env;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Slim\Factory\AppFactory;

require __DIR__ . '/vendor/autoload.php';

function kb(int $bytes): string
{
    return number_format($bytes / 1024, 1) . ' KB';
}

function stage(string $name, callable $fn): mixed
{
    gc_collect_cycles();
    $before = memory_get_usage();
    $files = count(get_included_files());
    $result = $fn();
    gc_collect_cycles();
    printf("  %-38s %10s  (+%d files)\n", $name, kb(memory_get_usage() - $before), count(get_included_files()) - $files);

    return $result;
}

function requests(string $name, callable $handle, int $n = 2000): void
{
    for ($i = 0; $i < 100; $i++) {
        $handle();
    }
    gc_collect_cycles();
    $before = memory_get_usage();
    $peakBefore = memory_get_peak_usage();
    for ($i = 0; $i < $n; $i++) {
        $handle();
    }
    $after = memory_get_usage();
    gc_collect_cycles();
    printf("  %-38s steady %+s over %d requests (%+.1f B/request), peak growth %s\n", $name, kb($after - $before), $n, ($after - $before) / $n, kb(memory_get_peak_usage() - $peakBefore));
}

echo "Keel (prod, warm caches)\n";
$app = stage('App::boot', static fn(): App => App::boot(__DIR__ . '/keel-app', new Env([])));
$request = new ServerRequest('GET', '/json/42');
requests('handle GET /json/42', static fn(): string => (string) $app->kernel->handle($request)->getBody());
printf("  %-38s %10s\n", 'memory_get_usage total', kb(memory_get_usage()));

echo "\nSlim\n";
$slim = stage('AppFactory + routes', static function () {
    $psr17 = new Psr17Factory();
    AppFactory::setResponseFactory($psr17);
    $app = AppFactory::create();
    $app->get('/json/{id:\d+}', static function ($request, $response, array $args) {
        $response->getBody()->write(json_encode(['id' => (int) $args['id'], 'name' => 'item ' . $args['id'], 'ok' => true]));

        return $response->withHeader('Content-Type', 'application/json');
    });

    return $app;
});
requests('handle GET /json/42', static fn(): string => (string) $slim->handle($request)->getBody());
printf("  %-38s %10s\n", 'memory_get_usage total', kb(memory_get_usage()));
