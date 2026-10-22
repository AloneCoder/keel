<?php

declare(strict_types=1);

namespace Keel\Routing;

use Keel\Routing\Attribute\Redirect;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The handler of every route declared with #[Redirect]: answers with the status and Location
 * recorded on the route, substituting the matched placeholders into the target
 * ("/old/{id}" to "/new/{id}"). Registered by the kernel; never called directly.
 */
final class Redirector
{
    public function __construct(private readonly ResponseFactoryInterface $responses) {}

    public function redirect(Route $route, RouteMatch $match): ResponseInterface
    {
        $option = $route->option(Redirect::KEY);
        $to = is_array($option) && is_string($option['to'] ?? null) ? $option['to'] : null;
        $status = is_array($option) && is_int($option['status'] ?? null) ? $option['status'] : null;
        if ($to === null || $status === null) {
            throw new \LogicException(sprintf('Route %s is handled by %s but carries no redirect option', $route->path, self::class));
        }

        $location = (string) preg_replace_callback(
            Route::PLACEHOLDER,
            static fn(array $m): string => array_key_exists($m[1], $match->params) ? rawurlencode($match->params[$m[1]]) : $m[0],
            $to,
        );

        return $this->responses->createResponse($status)->withHeader('Location', $location);
    }
}
