<?php

declare(strict_types=1);

namespace Keel\OpenApi;

/**
 * Reads the array element types a signature cannot carry: "@param list<Item> $items" and
 * "@return list<User>". Class names are resolved like PHP does: fully qualified names as is,
 * then through the "use" imports of the declaring file, then against the declaring namespace.
 *
 * @internal
 */
final class DocTypes
{
    /** @var array<string, array<string, string>> file => alias (lowercased) => class */
    private static array $imports = [];

    /**
     * Element type of an array-typed parameter: a class name, a scalar type name, or null.
     */
    public static function paramItems(\ReflectionParameter $parameter): ?string
    {
        $function = $parameter->getDeclaringFunction();
        $doc = $function->getDocComment();
        if ($doc === false) {
            return null;
        }
        if (preg_match('/@param\s+(\S+)\s+\$' . preg_quote($parameter->getName(), '/') . '\b/', $doc, $m) !== 1) {
            return null;
        }

        return self::items($m[1], $function);
    }

    /**
     * Element type of an array return: from "@return list<T>".
     */
    public static function returnItems(\ReflectionFunctionAbstract $function): ?string
    {
        $doc = $function->getDocComment();
        if ($doc === false || preg_match('/@return\s+(\S+)/', $doc, $m) !== 1) {
            return null;
        }

        return self::items($m[1], $function);
    }

    private static function items(string $type, \ReflectionFunctionAbstract $function): ?string
    {
        $type = trim($type, '()');
        if (preg_match('/^(?:list|array|iterable|non-empty-list|non-empty-array)<(?:[^,<>]+,\s*)?([^<>]+)>$/', $type, $m) === 1) {
            $inner = trim($m[1]);
        } elseif (preg_match('/^(\S+?)\[\]$/', $type, $m) === 1) {
            $inner = $m[1];
        } else {
            return null;
        }

        $inner = ltrim($inner, '?');
        if (in_array($inner, ['int', 'integer', 'float', 'string', 'bool', 'boolean', 'mixed', 'array'], true)) {
            return $inner;
        }

        return self::resolveClass($inner, $function);
    }

    private static function resolveClass(string $name, \ReflectionFunctionAbstract $function): ?string
    {
        if (str_starts_with($name, '\\')) {
            return self::existing(substr($name, 1));
        }

        $file = $function->getFileName();
        if ($file !== false) {
            $segments = explode('\\', $name, 2);
            $imported = self::imports($file)[strtolower($segments[0])] ?? null;
            if ($imported !== null) {
                $candidate = self::existing(isset($segments[1]) ? $imported . '\\' . $segments[1] : $imported);
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        $namespace = $function instanceof \ReflectionMethod ? $function->getDeclaringClass()->getNamespaceName() : $function->getNamespaceName();

        return self::existing($namespace === '' ? $name : $namespace . '\\' . $name) ?? self::existing($name);
    }

    private static function existing(string $class): ?string
    {
        return class_exists($class) || enum_exists($class) ? $class : null;
    }

    /**
     * Class imports of a file: "use Foo\Bar;", "use Foo\Bar as Baz;" and "use Foo\{Bar, Baz as Q};".
     * Function and constant imports are skipped. Files with several namespaces share one map.
     *
     * @return array<string, string> lowercased alias => fully qualified class
     */
    private static function imports(string $file): array
    {
        if (isset(self::$imports[$file])) {
            return self::$imports[$file];
        }

        $imports = [];
        $source = @file_get_contents($file);
        if ($source !== false && preg_match_all('/^\s*use\s+(?!function\s|const\s)([^;]+);/mi', $source, $matches) > 0) {
            foreach ($matches[1] as $clause) {
                foreach (self::expand(trim($clause)) as $alias => $class) {
                    $imports[strtolower($alias)] = $class;
                }
            }
        }

        return self::$imports[$file] = $imports;
    }

    /**
     * @return array<string, string> alias => class
     */
    private static function expand(string $clause): array
    {
        $pairs = [];
        if (preg_match('/^([^{]+)\{(.*)\}$/s', $clause, $m) === 1) {
            $prefix = trim($m[1], " \t\n\\");
            foreach (explode(',', $m[2]) as $member) {
                $pairs += self::pair($prefix . '\\' . trim($member));
            }

            return $pairs;
        }
        foreach (explode(',', $clause) as $member) {
            $pairs += self::pair(trim($member));
        }

        return $pairs;
    }

    /**
     * @return array<string, string>
     */
    private static function pair(string $import): array
    {
        $import = ltrim(trim($import), '\\');
        if ($import === '' || preg_match('/^(?:function|const)\s/i', $import) === 1) {
            return [];
        }
        $parts = preg_split('/\s+as\s+/i', $import) ?: [$import];
        $class = trim($parts[0]);
        $alias = isset($parts[1]) ? trim($parts[1]) : substr($class, (int) strrpos('\\' . $class, '\\'));

        return $class === '' || $alias === '' ? [] : [$alias => $class];
    }
}
