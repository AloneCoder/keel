<?php

declare(strict_types=1);

namespace Keel\Runtime;

use Keel\Kernel\Kernel;

/**
 * Picks the runtime for the current process: KEEL_RUNTIME=fpm|frankenphp|roadrunner wins;
 * otherwise FrankenPHP worker mode and RoadRunner are detected from their environment,
 * and everything else runs as a single php-fpm style request.
 */
final class Runtime
{
    /**
     * @param array<string, mixed>|null $server defaults to $_SERVER
     */
    public static function detect(?array $server = null): RuntimeInterface
    {
        $server ??= $_SERVER;
        $name = $server['KEEL_RUNTIME'] ?? getenv('KEEL_RUNTIME');

        if (is_string($name) && $name !== '') {
            return match (strtolower($name)) {
                'fpm', 'sapi' => new FpmRuntime(),
                'frankenphp' => new FrankenPhpRuntime(),
                'roadrunner' => new RoadRunnerRuntime(),
                default => throw new \InvalidArgumentException(sprintf('Unknown KEEL_RUNTIME "%s"', $name)),
            };
        }

        if (function_exists('frankenphp_handle_request') && ($server['FRANKENPHP_WORKER'] ?? '') !== '') {
            return new FrankenPhpRuntime();
        }

        if (($server['RR_MODE'] ?? '') === 'http') {
            return new RoadRunnerRuntime();
        }

        return new FpmRuntime();
    }

    public static function run(Kernel $kernel): int
    {
        return self::detect()->run($kernel);
    }
}
