<?php

declare(strict_types=1);

use Keel\App\App;
use Keel\Config\Env;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// Worker mode: App::boot() once (prod caches), then the FrankenPHP loop via Runtime::detect().
exit(App::boot(dirname(__DIR__, 3) . '/keel-app', new Env(['APP_ENV' => 'prod']))->run());
