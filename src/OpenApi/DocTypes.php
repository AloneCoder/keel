<?php

declare(strict_types=1);

namespace Keel\OpenApi;

/**
 * Reads the array element types a signature cannot carry: "@param list<Item> $items" and
 * "@return list<User>". Class names are resolved against the declaring namespace.
 *
 * @internal
 */
final class DocTypes
{
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
            $name = substr($name, 1);

            return class_exists($name) || enum_exists($name) ? $name : null;
        }
        if (class_exists($name) || enum_exists($name)) {
            return $name;
        }

        $namespace = $function instanceof \ReflectionMethod ? $function->getDeclaringClass()->getNamespaceName() : $function->getNamespaceName();
        $candidate = $namespace === '' ? $name : $namespace . '\\' . $name;

        return class_exists($candidate) || enum_exists($candidate) ? $candidate : null;
    }
}
