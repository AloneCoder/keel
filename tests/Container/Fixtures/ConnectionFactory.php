<?php

declare(strict_types=1);

namespace Keel\Tests\Container\Fixtures;

use Keel\Container\Attribute\Inject;

final class ConnectionFactory
{
    public function __construct(private readonly Logger $logger) {}

    public static function create(#[Inject('db.dsn')] string $dsn, Logger $logger): Connection
    {
        return new Connection($dsn, $logger);
    }

    public function open(#[Inject('db.dsn')] string $dsn): Connection
    {
        return new Connection($dsn . '?pool', $this->logger);
    }
}
