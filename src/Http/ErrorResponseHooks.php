<?php

declare(strict_types=1);

namespace Keel\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What must still happen to the response when the request ends in an exception. Exceptions travel
 * past the code a middleware runs after $handler->handle(), up to the kernel's exception handler,
 * which leaves one of these on the request and runs its hooks on the error response it renders:
 *
 *   ErrorResponseHooks::of($request)?->add(fn(ResponseInterface $r) => $r->withHeader('X-Foo', 'bar'));
 *
 * Hooks run innermost first, like after-logic would. A hook that throws is logged and skipped.
 */
final class ErrorResponseHooks
{
    /** @var list<\Closure(ResponseInterface): ResponseInterface> */
    private array $hooks = [];

    /**
     * The hooks of a request; null outside the kernel, where no exception handler would run them.
     */
    public static function of(ServerRequestInterface $request): ?self
    {
        $hooks = $request->getAttribute(self::class);

        return $hooks instanceof self ? $hooks : null;
    }

    /**
     * @param \Closure(ResponseInterface): ResponseInterface $hook
     */
    public function add(\Closure $hook): void
    {
        $this->hooks[] = $hook;
    }

    /**
     * @return list<\Closure(ResponseInterface): ResponseInterface> innermost first
     */
    public function all(): array
    {
        return array_reverse($this->hooks);
    }
}
