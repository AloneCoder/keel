<?php

declare(strict_types=1);

namespace Keel\Runtime;

/**
 * In-flight requests counted in APCu, shared by the php-fpm children of a pool or the threads of
 * a FrankenPHP server (not by RoadRunner workers, which are separate processes with their own APCu).
 *
 * A worker killed between enter() and leave() (a fatal error, a timeout) would leave its count
 * behind for good, so the counter lives for $window seconds and then starts from zero again: the
 * error is bounded to one window, and the count can only be too low, never stuck too high.
 * When APCu cannot store the counter the result is 0: shedding fails open.
 */
final class ApcuInFlightCounter implements InFlightCounter
{
    public function __construct(private readonly string $key = 'keel:in_flight', private readonly int $window = 60)
    {
        if (!self::available()) {
            throw new \RuntimeException('APCu is not available in this SAPI');
        }
    }

    public static function available(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    public function enter(): int
    {
        $success = false;
        $value = apcu_inc($this->key, 1, $success, $this->window);

        return $success && is_int($value) ? $value : 0;
    }

    public function leave(): void
    {
        $success = false;
        $value = apcu_dec($this->key, 1, $success, $this->window);
        if ($success && is_int($value) && $value < 0) {
            // The window rolled over while requests were in flight: their leave() lands on the new counter.
            apcu_cas($this->key, $value, 0);
        }
    }
}
