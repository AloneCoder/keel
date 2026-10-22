<?php

declare(strict_types=1);

namespace Keel\Routing\Attribute;

/**
 * One PSR-15 middleware with constructor arguments, for middleware that carries no attribute
 * of its own (third-party or generic ones): #[MiddlewareWith(Throttle::class, limit: 5, window: 60)].
 * Named arguments go to the constructor parameters of that name; the rest are autowired from
 * the container. The middleware is instantiated once per route and kept for the worker's life,
 * so it must not hold per-request state. Values must be exportable (scalars, arrays, null):
 * they travel through the route cache.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class MiddlewareWith implements MiddlewareProvider
{
    /** @var array<string, mixed> */
    public array $arguments;

    /**
     * @param class-string<\Psr\Http\Server\MiddlewareInterface> $class
     */
    public function __construct(public string $class, mixed ...$arguments)
    {
        $named = [];
        foreach ($arguments as $name => $value) {
            if (!is_string($name)) {
                throw new \InvalidArgumentException(sprintf('MiddlewareWith(%s) takes named arguments only, e.g. limit: 5', $class));
            }
            $named[$name] = $value;
        }
        $this->arguments = $named;
    }

    public function middleware(): array
    {
        return [[$this->class, $this->arguments]];
    }
}
