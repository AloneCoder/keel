<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

use Keel\Container\Attribute\Inject;

final class Database
{
    public function __construct(
        #[Inject('db.dsn')]
        public readonly string $dsn,
        public readonly int $timeout = 5,
        public readonly ?Logger $logger = null,
        public readonly ?Clock $clock = null,
        public readonly Mode $mode = Mode::ReadWrite,
    ) {}
}
