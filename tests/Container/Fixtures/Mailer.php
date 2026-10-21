<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

final class Mailer
{
    public function __construct(public readonly Logger $logger) {}
}
