<?php

declare(strict_types=1);

use Keel\Container\Definitions;
use Keel\Examples\Hello\HelloController;
use Keel\Http\SapiEmitter;
use Keel\Kernel\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// Front controller for php-fpm / the built-in server:
//   php -S 127.0.0.1:8080 -t examples/hello/public examples/hello/public/index.php
$kernel = Kernel::build(new Definitions(), [HelloController::class], debug: true);

$psr17 = new Psr17Factory();
$request = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17)->fromGlobals();

$response = $kernel->handle($request);
new SapiEmitter()->emit($response);
$kernel->terminate();
