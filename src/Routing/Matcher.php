<?php

declare(strict_types=1);

namespace Keel\Routing;

use Keel\Routing\Exception\RoutingException;

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
     * A placeholder value must satisfy its constraint once decoded too ("%2E%2E" does not get past
     * [^.]+) and never decodes to a NUL byte; otherwise the route does not match.
     *
     * @param string $path raw (undecoded) request path; placeholders are decoded individually
     *
     * @throws RoutingException when PCRE fails (backtrack limit): treating that as "no match" would
     *                          hand the request to a later route with other middleware
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
            $matched = preg_match($chunk['regex'], $path, $matches);
            if ($matched === false) {
                throw new RoutingException('Route matching failed: ' . preg_last_error_msg());
            }
            if ($matched !== 1) {
                continue;
            }

            $entry = $chunk['map'][count($matches)];
            [$index, $variables] = $entry;
            $checks = $entry[2] ?? [];
            $params = [];
            foreach ($variables as $position => $name) {
                $value = rawurldecode($matches[$position + 1]);
                if (str_contains($value, "\0") || (isset($checks[$position]) && preg_match($checks[$position], $value) !== 1)) {
                    return null;
                }
                $params[$name] = $value;
            }

            return MatchResult::found($this->routes->route($index), $params);
        }

        return null;
    }
}
