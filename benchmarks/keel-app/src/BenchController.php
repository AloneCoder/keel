<?php

declare(strict_types=1);

namespace Keel\Benchmarks\KeelApp;

use Keel\Routing\Attribute\Get;

final class BenchController
{
    /** @return array<string, mixed> */
    #[Get('/json/{id:\d+}', name: 'json')]
    public function json(int $id): array
    {
        return ['id' => $id, 'name' => 'item ' . $id, 'ok' => true];
    }

    #[Get('/plain')]
    public function plain(): string
    {
        return 'Hello';
    }
}
