<?php

declare(strict_types=1);

namespace Keel\Config;

/**
 * Loads config/*.php. Each file returns a config object or a closure (Env $env, string $projectDir): object.
 * Objects are registered in the container under their class, so services type-hint them.
 */
final class ConfigLoader
{
    /**
     * @return array<class-string, object>
     */
    public function load(string $directory, Env $env, string $projectDir): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        // scandir, not glob: a project path with "[" or "*" must not silently load nothing.
        $entries = scandir($directory, SCANDIR_SORT_ASCENDING);
        if ($entries === false) {
            throw new \RuntimeException(sprintf('Cannot read config directory "%s"', $directory));
        }

        $configs = [];
        $sources = [];
        foreach ($entries as $entry) {
            $file = $directory . '/' . $entry;
            if (!str_ends_with($entry, '.php') || !is_file($file)) {
                continue;
            }

            $object = self::evaluate($file, $env, $projectDir);
            if (isset($sources[$object::class])) {
                throw new \RuntimeException(sprintf(
                    'Config files "%s" and "%s" both return a %s; one object per class',
                    $sources[$object::class],
                    $file,
                    $object::class,
                ));
            }
            $sources[$object::class] = $file;
            $configs[$object::class] = $object;
        }

        return $configs;
    }

    private static function evaluate(string $file, Env $env, string $projectDir): object
    {
        /** @var mixed $value */
        $value = (static fn(): mixed => require $file)();

        if ($value instanceof \Closure) {
            /** @var mixed $value */
            $value = $value($env, $projectDir);
        }

        if (!is_object($value)) {
            throw new \RuntimeException(sprintf(
                'Config file "%s" must return an object or a closure returning one, got %s',
                $file,
                get_debug_type($value),
            ));
        }

        return $value;
    }
}
