<?php

declare(strict_types=1);

namespace Keel\Routing;

/**
 * @internal
 */
final class Matcher
{
    public function __construct(private readonly CompiledRoutes $routes) {}

    /**
     * Methods are case-sensitive (RFC 9110 §9.1): "post" is not POST and gets 405, so everything
     * keyed by the method (rate limits, caches) sees the same spelling the route matched.
     *
     * @param string $path raw (undecoded) request path; placeholders are decoded individually
     */
    public function match(string $method, string $path): MatchResult
    {
        $result = $this->matchMethod($method, $path);
        if ($result !== null) {
            return $result;
        }

        if ($method === 'HEAD') {
            $result = $this->matchMethod('GET', $path);
            if ($result !== null) {
                return $result;
            }
        }

        $allowed = [];
        foreach (array_keys($this->routes->static + $this->routes->dynamic) as $candidate) {
            if ($candidate !== $method && $this->matchMethod($candidate, $path) !== null) {
                $allowed[] = $candidate;
                if ($candidate === 'GET') {
                    $allowed[] = 'HEAD'; // served by the GET route, so Allow must list it too
                }
            }
        }

        return $allowed === [] ? MatchResult::notFound() : MatchResult::methodNotAllowed(array_values(array_unique($allowed)));
    }

    private function matchMethod(string $method, string $path): ?MatchResult
    {
        $index = $this->routes->static[$method][$path] ?? null;
        if ($index !== null) {
            return MatchResult::found($this->routes->route($index), []);
        }

        foreach ($this->routes->dynamic[$method] ?? [] as $chunk) {
            if (preg_match($chunk['regex'], $path, $matches) !== 1) {
                continue;
            }

            [$index, $variables] = $chunk['map'][count($matches)];
            $params = [];
            foreach ($variables as $position => $name) {
                $params[$name] = rawurldecode($matches[$position + 1]);
            }

            return MatchResult::found($this->routes->route($index), $params);
        }

        return null;
    }
}
