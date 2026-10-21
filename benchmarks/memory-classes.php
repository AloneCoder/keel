<?php

declare(strict_types=1);

use Keel\App\App;
use Keel\Config\Env;

require __DIR__ . '/vendor/autoload.php';

$baseline = get_included_files();
$app = App::boot(__DIR__ . '/keel-app', new Env([]));
$afterBoot = get_included_files();
$app->kernel->handle(new \Nyholm\Psr7\ServerRequest('GET', '/json/42'));
$app->kernel->terminate();
$afterRequest = get_included_files();

$root = dirname(__DIR__);
$short = static fn(string $f): string => str_replace([$root . '/vendor/', $root . '/'], ['vendor/', ''], $f);
echo "Loaded at boot (" . (count($afterBoot) - count($baseline)) . "):\n";
foreach (array_diff($afterBoot, $baseline) as $f) {
    echo '  ', $short($f), "\n";
}
echo "\nLoaded by first request (" . (count($afterRequest) - count($afterBoot)) . "):\n";
foreach (array_diff($afterRequest, $afterBoot) as $f) {
    echo '  ', $short($f), "\n";
}
