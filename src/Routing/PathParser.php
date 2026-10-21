<?php

declare(strict_types=1);

namespace Keel\Routing;

use Keel\Routing\Exception\RoutingException;

/**
 * Turns "/users/{id:\d+}/posts/{slug}" into a regex and the ordered list of placeholder names.
 *
 * @internal
 */
final class PathParser
{
    public const string DEFAULT_PATTERN = '[^/]+';

    private const string PLACEHOLDER = '~\{([a-zA-Z_][a-zA-Z0-9_]*)(?::((?:[^{}]|\{(?-1)\})*))?\}~';

    /**
     * @return array{regex: string|null, variables: list<string>} regex is null for static paths
     */
    public function parse(string $path): array
    {
        if ($path === '' || $path[0] !== '/') {
            throw new RoutingException(sprintf('Route path "%s" must start with "/"', $path));
        }

        $variables = [];
        $regex = '';
        $offset = 0;
        preg_match_all(self::PLACEHOLDER, $path, $matches, \PREG_OFFSET_CAPTURE | \PREG_SET_ORDER);
        foreach ($matches as $match) {
            [$whole, $position] = $match[0];
            $name = $match[1][0];
            $pattern = isset($match[2]) && $match[2][0] !== '' ? $match[2][0] : self::DEFAULT_PATTERN;

            if (in_array($name, $variables, true)) {
                throw new RoutingException(sprintf('Placeholder "%s" is used twice in "%s"', $name, $path));
            }
            if (@preg_match('~^(?:' . $pattern . ')$~', '') === false) {
                throw new RoutingException(sprintf('Invalid pattern for "%s" in "%s": %s', $name, $path, $pattern));
            }

            $variables[] = $name;
            $regex .= preg_quote(substr($path, $offset, $position - $offset), '~');
            $regex .= '(' . $this->withoutCapturingGroups($pattern) . ')';
            $offset = $position + strlen($whole);
        }

        if ($variables === []) {
            if (str_contains($path, '{') || str_contains($path, '}')) {
                throw new RoutingException(sprintf('Malformed placeholder in "%s"', $path));
            }

            return ['regex' => null, 'variables' => []];
        }

        $regex .= preg_quote(substr($path, $offset), '~');

        return ['regex' => $regex, 'variables' => $variables];
    }

    /**
     * User patterns must not shift group numbering: "(" becomes "(?:" unless it already is a special group.
     */
    private function withoutCapturingGroups(string $pattern): string
    {
        return (string) preg_replace('~(?<!\\\\)\((?!\?)~', '(?:', $pattern);
    }
}
