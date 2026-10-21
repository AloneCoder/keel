<?php

declare(strict_types=1);

namespace Keel\Tests\App\Fixtures\Broken\Src;

use Keel\Container\Definitions;
use Keel\Container\Ref;
use Keel\Container\ServiceProvider;
use Keel\Tests\Container\Fixtures\Mailer;

/**
 * Registers a service whose dependency does not exist: nothing notices until it is requested.
 */
final class BrokenProvider implements ServiceProvider
{
    public function register(Definitions $definitions): void
    {
        $definitions->set('broken.mailer', Mailer::class)->argument('logger', new Ref('missing.logger'));
    }
}
