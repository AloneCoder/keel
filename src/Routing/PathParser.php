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
     * Expands optional trailing segments into the paths they stand for: "/users[/{id}[/{tab}]]"
     * yields "/users", "/users/{id}" and "/users/{id}/{tab}", shortest first (FastRoute syntax).
     * Optional parts are only allowed at the end of the path, nested or not; a "[" inside a
     * placeholder constraint ("{slug:[a-z]+}") is a character class, not a marker.
     *
     * @return list<string>
     */
    public function expand(string $path): array
    {
        // Mask placeholders (same length, so offsets stay valid) so their constraints are not read as markers.
        $masked = (string) preg_replace_callback(self::PLACEHOLDER, static fn(array $m): string => str_repeat("\0", strlen($m[0])), $path);
        if (!str_contains($masked, '[') && !str_contains($masked, ']')) {
            return [$path];
        }

        $open = substr_count($masked, '[');
        $trailing = strlen($masked) - strlen(rtrim($masked, ']'));
        if ($open === 0 || $open !== substr_count($masked, ']') || $trailing !== $open) {
            throw new RoutingException(sprintf('Optional segments in "%s" must be bracketed at the end of the path: "/users[/{id}]"', $path));
        }

        $body = substr($masked, 0, -$trailing);
        $parts = explode('[', $body);
        $variants = [];
        $offset = 0;
        $current = '';
        foreach ($parts as $i => $part) {
            if ($i > 0 && $part === '') {
                throw new RoutingException(sprintf('Empty optional segment in "%s"', $path));
            }
            $current .= substr($path, $offset, strlen($part));
            $offset += strlen($part) + 1;
            $variants[] = $current;
        }

        return $variants;
    }

    /**
     * @return array{regex: string|null, variables: list<string>, patterns: list<string>} regex is null for static paths;
     *                                                                                   patterns are the sanitised per-placeholder constraints, in placeholder order
     */
    public function parse(string $path): array
    {
        if ($path === '' || $path[0] !== '/') {
            throw new RoutingException(sprintf('Route path "%s" must start with "/"', $path));
        }

        $variables = [];
        $patterns = [];
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

            $pattern = $this->withoutCapturingGroups($pattern);
            // Validated on its own, so that a stray ")" or "(" cannot pair with the wrapping group and
            // smuggle an alternative into the chunk regex; the "|" probe makes the match succeed so
            // that PREG_UNMATCHED_AS_NULL reports every group the pattern declares.
            if (@preg_match('~' . $pattern . '~', '') === false
                || @preg_match('~(?:' . $pattern . ')|~', '', $probe, \PREG_UNMATCHED_AS_NULL) !== 1) {
                throw new RoutingException(sprintf('Invalid pattern for "%s" in "%s": %s', $name, $path, $pattern));
            }
            if (count(array_filter(array_keys($probe), is_int(...))) !== 1) {
                // Named groups and groups behind an escaped backslash cannot be neutralised; they would
                // shift the group numbering that identifies the matched route within a chunk.
                throw new RoutingException(sprintf('Pattern for "%s" in "%s" must not contain capturing groups: %s', $name, $path, $pattern));
            }

            $variables[] = $name;
            $patterns[] = $pattern;
            $regex .= preg_quote(substr($path, $offset, $position - $offset), '~');
            $regex .= '(' . $pattern . ')';
            $offset = $position + strlen($whole);
        }

        if ($variables === []) {
            if (str_contains($path, '{') || str_contains($path, '}')) {
                throw new RoutingException(sprintf('Malformed placeholder in "%s"', $path));
            }

            return ['regex' => null, 'variables' => [], 'patterns' => []];
        }

        $regex .= preg_quote(substr($path, $offset), '~');

        return ['regex' => $regex, 'variables' => $variables, 'patterns' => $patterns];
    }

    /**
     * User patterns must not shift group numbering: a plain "(" becomes "(?:". Groups that already
     * start with "(?" are left alone, as are escaped parentheses and parentheses inside [...] classes.
     */
    private function withoutCapturingGroups(string $pattern): string
    {
        $result = '';
        $inClass = false;
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $result .= $char . $pattern[++$i];
                continue;
            }
            if ($inClass) {
                $inClass = $char !== ']';
            } elseif ($char === '[') {
                $inClass = true;
            } elseif ($char === '(' && ($pattern[$i + 1] ?? '') !== '?') {
                $result .= '(?:';
                continue;
            }
            $result .= $char;
        }

        return $result;
    }
}
