<?php

declare(strict_types=1);

namespace Keel\Event\Attribute;

/**
 * Marks a public method as an event listener. The event type is the type of the first
 * parameter unless given explicitly. Higher priority runs first.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class Listener
{
    /**
     * @param class-string|null $event
     */
    public function __construct(public ?string $event = null, public int $priority = 0) {}
}
