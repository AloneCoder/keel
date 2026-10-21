<?php

declare(strict_types=1);

use Keel\App\App;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// One front controller for every runtime. Runtime::detect() picks:
//   php -S 127.0.0.1:8080 -t examples/hello/public examples/hello/public/index.php   -> FpmRuntime
//   frankenphp run --config examples/hello/Caddyfile                                -> FrankenPhpRuntime (worker mode)
//   rr serve (with KEEL_RUNTIME=roadrunner or RR_MODE=http)                         -> RoadRunnerRuntime
// App::boot() reads .env and config/*.php, discovers src/, and in prod uses var/cache/<env>/.
exit(App::boot(dirname(__DIR__))->run());
