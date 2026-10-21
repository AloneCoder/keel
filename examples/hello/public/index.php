<?php

declare(strict_types=1);

use Keel\Container\Definitions;
use Keel\Examples\Hello\HelloController;
use Keel\Kernel\Kernel;
use Keel\Runtime\Runtime;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// One front controller for every runtime. Runtime::detect() picks:
//   php -S 127.0.0.1:8080 -t examples/hello/public examples/hello/public/index.php   -> FpmRuntime
//   frankenphp run --config examples/hello/Caddyfile                                -> FrankenPhpRuntime (worker mode)
//   rr serve (with KEEL_RUNTIME=roadrunner or RR_MODE=http)                         -> RoadRunnerRuntime
$kernel = Kernel::build(new Definitions(), [HelloController::class], debug: true);

exit(Runtime::run($kernel));
