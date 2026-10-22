<?php

declare(strict_types=1);

namespace Keel\Console\Install;

/**
 * Adds a class to the list a config file passes to its config object: a provider to
 * `new ServicesConfig([...])`, a middleware to `new HttpConfig(middleware: [...])`. Works on the
 * tokens of the file, so formatting and comments survive; imports the class with a `use` line
 * unless its short name is taken. A file that does not exist is created.
 *
 * @internal used by keel:install
 */
final class ConfigEditor
{
    public const string CREATED = 'created';

    public const string ADDED = 'added';

    public const string PRESENT = 'present';

    /** The list was not found: the file is left alone and the entry must be added by hand. */
    public const string MANUAL = 'manual';

    /**
     * @param class-string $configClass e.g. ServicesConfig::class
     * @param class-string $entry       the class to add
     * @param string       $argument    named argument used when the file is created ("providers", "middleware")
     *
     * @return self::CREATED|self::ADDED|self::PRESENT|self::MANUAL
     */
    public static function addToFile(string $path, string $configClass, string $entry, string $argument, bool $dryRun = false): string
    {
        if (!is_file($path)) {
            if (!$dryRun) {
                self::write($path, self::create($configClass, $entry, $argument));
            }

            return self::CREATED;
        }

        $source = (string) file_get_contents($path);
        $edited = self::add($source, $configClass, $entry);
        if ($edited === null) {
            return self::MANUAL;
        }
        if ($edited === $source) {
            return self::PRESENT;
        }
        if (!$dryRun) {
            self::write($path, $edited);
        }

        return self::ADDED;
    }

    /**
     * @param class-string $configClass
     * @param class-string $entry
     */
    public static function create(string $configClass, string $entry, string $argument): string
    {
        $imports = [$configClass, $entry];
        sort($imports);

        return "<?php\n\ndeclare(strict_types=1);\n\n"
            . implode('', array_map(static fn(string $class): string => 'use ' . $class . ";\n", $imports))
            . "\nreturn new " . self::short($configClass) . '(' . $argument . ": [\n    " . self::short($entry) . "::class,\n]);\n";
    }

    /**
     * The source with $entry added to the list of the first `new <ConfigClass>(` call; the source
     * unchanged when the entry is there already; null when no such list is found.
     *
     * @param class-string $configClass
     * @param class-string $entry
     */
    public static function add(string $source, string $configClass, string $entry): ?string
    {
        $imports = self::imports($source);
        $short = self::short($entry);
        $imported = ($imports[strtolower($short)] ?? null) === strtolower($entry);
        if (str_contains($source, '\\' . $entry . '::class') || ($imported && preg_match('/(?<![\w\\\\])' . preg_quote($short, '/') . '::class/', $source) === 1)) {
            return $source;
        }

        $list = self::findList($source, self::short($configClass));
        if ($list === null) {
            return null;
        }

        $useShort = $imported || !isset($imports[strtolower($short)]);
        $reference = ($useShort ? $short : '\\' . $entry) . '::class';
        $edited = self::insert($source, $list, $reference);

        return $useShort && !$imported ? self::import($edited, $entry) : $edited;
    }

    /**
     * The first array literal argument of `new <class>(`: offsets of "[" and "]", the end of the
     * last element (or of "[" when empty) and whether that element is followed by a comma.
     *
     * @return array{open: int, close: int, last: int, comma: bool}|null
     */
    private static function findList(string $source, string $class): ?array
    {
        $tokens = array_values(\PhpToken::tokenize($source));
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!$tokens[$i]->is(\T_NEW)) {
                continue;
            }
            $name = self::next($tokens, $i);
            if ($name === null || !$tokens[$name]->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
                continue;
            }
            $segments = explode('\\', $tokens[$name]->text);
            if (strcasecmp(end($segments), $class) !== 0) {
                continue;
            }
            $paren = self::next($tokens, $name);
            if ($paren === null || $tokens[$paren]->text !== '(') {
                continue;
            }

            $parens = 1;
            $open = null;
            $brackets = 0;
            $previous = $paren;
            $last = null;
            $comma = false;
            for ($j = $paren + 1; $j < $count && $parens > 0; $j++) {
                $token = $tokens[$j];
                if ($token->isIgnorable()) {
                    continue;
                }
                $text = $token->text;
                if ($text === '(') {
                    $parens++;
                } elseif ($text === ')') {
                    $parens--;
                } elseif ($text === '[') {
                    // Only an array that starts an argument: after "(", "," or a named argument's ":".
                    if ($open === null && $parens === 1 && $brackets === 0 && in_array($tokens[$previous]->text, ['(', ',', ':'], true)) {
                        $open = $token->pos;
                    }
                    $brackets++;
                } elseif ($text === ']') {
                    $brackets--;
                    if ($open !== null && $brackets === 0) {
                        return ['open' => $open, 'close' => $token->pos, 'last' => $last ?? $open + 1, 'comma' => $comma];
                    }
                }
                // Every token that ends at the list's own depth may be the end of its last element.
                if ($open !== null && $brackets === 1 && $token->pos !== $open) {
                    $comma = $text === ',';
                    $last = $token->pos + strlen($text);
                }
                $previous = $j;
            }
        }

        return null;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private static function next(array $tokens, int $index): ?int
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            if (!$tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The source with $reference appended to the list, following its layout: on a line of its
     * own in a multi-line list (after any comment that ends the last line), inline otherwise.
     *
     * @param array{open: int, close: int, last: int, comma: bool} $list
     */
    private static function insert(string $source, array $list, string $reference): string
    {
        ['open' => $open, 'close' => $close, 'last' => $last, 'comma' => $comma] = $list;
        $inner = substr($source, $open + 1, $close - $open - 1);
        $lineIndent = self::lineIndent($source, $open);
        $empty = $last === $open + 1;

        if ($empty && trim($inner) === '') {
            return substr($source, 0, $open + 1) . "\n" . $lineIndent . '    ' . $reference . ",\n" . $lineIndent . substr($source, $close);
        }
        if (!str_contains($inner, "\n")) {
            return substr($source, 0, $last) . ($empty || $comma ? ($empty ? '' : ' ') : ', ') . $reference . substr($source, $last, $close - $last) . substr($source, $close);
        }

        $indent = preg_match('/\n([ \t]*)\S/', $inner, $parts) === 1 ? $parts[1] : $lineIndent . '    ';
        if (!$empty && !$comma) {
            $source = substr($source, 0, $last) . ',' . substr($source, $last);
            $last++;
            $close++;
        }
        $eol = strpos($source, "\n", $last);
        if ($eol === false || $eol > $close) {
            return substr($source, 0, $close) . "\n" . $indent . $reference . ",\n" . $lineIndent . substr($source, $close);
        }

        return substr($source, 0, $eol) . "\n" . $indent . $reference . ',' . substr($source, $eol);
    }

    private static function lineIndent(string $source, int $offset): string
    {
        $start = strrpos(substr($source, 0, $offset), "\n");
        $line = substr($source, $start === false ? 0 : $start + 1, $offset);

        return preg_match('/^[ \t]*/', $line, $parts) === 1 ? $parts[0] : '';
    }

    /**
     * Short names (lower case) to imported classes (lower case), from plain `use` lines.
     *
     * @return array<string, string>
     */
    private static function imports(string $source): array
    {
        $imports = [];
        preg_match_all('/^use\s+\\\\?([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/mi', $source, $matches, \PREG_SET_ORDER);
        foreach ($matches as $match) {
            $alias = $match[2] ?? '';
            $imports[strtolower($alias !== '' ? $alias : self::short($match[1]))] = strtolower($match[1]);
        }

        return $imports;
    }

    private static function import(string $source, string $class): string
    {
        $line = 'use ' . $class . ";\n";
        if (preg_match_all('/^use\s+[^;]+;[^\n]*\n/m', $source, $matches, \PREG_OFFSET_CAPTURE) > 0) {
            $last = $matches[0][count($matches[0]) - 1];
            $end = $last[1] + strlen($last[0]);

            return substr($source, 0, $end) . $line . substr($source, $end);
        }
        if (preg_match('/^declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;[^\n]*\n/m', $source, $match, \PREG_OFFSET_CAPTURE) === 1) {
            $end = $match[0][1] + strlen($match[0][0]);

            return substr($source, 0, $end) . "\n" . $line . substr($source, $end);
        }

        return (string) preg_replace('/^<\?php\s*/', "<?php\n\n" . $line . "\n", $source, 1);
    }

    private static function short(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    private static function write(string $path, string $source): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Cannot create directory "%s"', $directory));
        }
        if (file_put_contents($path, $source) === false) {
            throw new \RuntimeException(sprintf('Cannot write "%s"', $path));
        }
    }
}
