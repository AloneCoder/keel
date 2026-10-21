<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

final class Newsletter
{
    public function __construct(public readonly Mailer $mailer, public readonly Logger $logger) {}
}
