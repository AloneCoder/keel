<?php

declare(strict_types=1);

namespace Keel\Tests\Kernel\Fixtures;

use Keel\Http\Attribute\FromBody;
use Keel\Routing\Attribute\Post;

final class BookingController
{
    /**
     * @return array<string, mixed>
     */
    #[Post('/bookings')]
    public function create(#[FromBody] Booking $booking): array
    {
        return ['title' => $booking->title, 'days' => $booking->range->end - $booking->range->start];
    }

    /**
     * @return array<string, mixed>
     */
    #[Post('/broken')]
    public function broken(#[FromBody] BrokenDto $dto): array
    {
        return ['name' => $dto->name];
    }
}
