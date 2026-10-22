<?php

declare(strict_types=1);

namespace Keel\Tests\Routing\Fixtures;

use Keel\Routing\Attribute\Middleware;
use Keel\Routing\Attribute\Prefix;

/**
 * A route group: every subclass inherits the prefix, the middleware and the option.
 */
#[Prefix('/api')]
#[Middleware(NoopMiddleware::class)]
#[Flag('group')]
abstract class ApiController {}
