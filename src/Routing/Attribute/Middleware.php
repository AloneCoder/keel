<?php

declare(strict_types=1);

namespace Keel\Routing\Attribute;

/**
 * PSR-15 middleware applied to every route of the class, or to one route when put on a method.
 * Class-level middleware runs before method-level middleware.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class Middleware
{
    /** @var list<class-string<\Psr\Http\Server\MiddlewareInterface>> */
    public array $classes;

    /**
     * @param class-string<\Psr\Http\Server\MiddlewareInterface> ...$classes
     */
    public function __construct(string ...$classes)
    {
        $this->classes = array_values($classes);
    }
}
