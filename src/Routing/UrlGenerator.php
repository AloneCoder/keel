<?php

declare(strict_types=1);

namespace Keel\Routing;

use Keel\Routing\Exception\RoutingException;

/**
 * @internal
 */
final class UrlGenerator
{
    public function __construct(
        private readonly CompiledRoutes $routes,
        private readonly PathParser $parser = new PathParser(),
    ) {}

    /**
     * Builds a path for a named route. Parameters that are not placeholders become the query string.
     *
     * @param array<string, scalar|\Stringable|null> $params
     */
    public function generate(string $name, array $params = []): string
    {
        $index = $this->routes->names[$name]
            ?? throw new RoutingException(sprintf('Route "%s" is not defined', $name));
        $route = $this->routes->route($index);
        $parsed = $this->parser->parse($route->path);

        $path = $route->path;
        foreach ($parsed['variables'] as $position => $variable) {
            if (!array_key_exists($variable, $params)) {
                throw new RoutingException(sprintf('Route "%s" needs parameter "%s"', $name, $variable));
            }

            // The value is checked against the constraint as the application sees it (decoded, with /u
            // so \p{L} and friends work) or as the matcher sees it (percent-encoded: "a/b" satisfies
            // [^/]+ once it is "a%2Fb").
            $value = (string) $params[$variable];
            $encoded = rawurlencode($value);
            $constraint = '~^(?:' . $parsed['patterns'][$position] . ')$~';
            if (preg_match($constraint . 'u', $value) !== 1 && preg_match($constraint, $encoded) !== 1) {
                throw new RoutingException(sprintf('Parameters for route "%s" do not satisfy its pattern: "%s" is not a valid "%s"', $name, $value, $variable));
            }

            $path = (string) preg_replace(
                '~\{' . preg_quote($variable, '~') . '(?::(?:[^{}]|\{[^{}]*\})*)?\}~',
                $encoded,
                $path,
                1,
            );
            unset($params[$variable]);
        }

        $query = array_filter($params, static fn(mixed $value): bool => $value !== null);

        return $query === [] ? $path : $path . '?' . http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
    }
}
