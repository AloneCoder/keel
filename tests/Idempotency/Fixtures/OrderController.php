<?php

declare(strict_types=1);

namespace Keel\Tests\Idempotency\Fixtures;

use Keel\Http\Attribute\FromBody;
use Keel\Idempotency\Attribute\Idempotent;
use Keel\Routing\Attribute\Patch;
use Keel\Routing\Attribute\Post;
use Keel\Routing\Attribute\Put;
use Keel\Routing\Attribute\Status;

final class OrderController
{
    public static int $created = 0;

    public static bool $fail = false;

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    #[Post('/orders')]
    #[Status(201)]
    #[Idempotent(required: true, ttl: 120)]
    public function create(#[FromBody] array $order): array
    {
        if (self::$fail) {
            throw new \RuntimeException('database down');
        }

        return ['id' => ++self::$created] + $order;
    }

    /** @return array<string, int> */
    #[Post('/payments')]
    public function pay(): array
    {
        return ['payment' => ++self::$created];
    }

    /** @return array<string, int> */
    #[Put('/carts/{id}')]
    #[Idempotent]
    public function replace(int $id): array
    {
        return ['cart' => $id, 'n' => ++self::$created];
    }

    /** @return array<string, int> */
    #[Patch('/notes')]
    public function note(): array
    {
        return ['note' => ++self::$created];
    }
}
