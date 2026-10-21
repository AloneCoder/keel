<?php

declare(strict_types=1);

namespace Keel\Metrics;

/**
 * Samples in APCu: aggregated over every php-fpm child or FrankenPHP thread of the server.
 */
final class ApcuStorage implements MetricsStorage
{
    public function __construct(private readonly string $prefix = 'keel:metrics:')
    {
        if (!self::available()) {
            throw new \RuntimeException('APCu is not available in this SAPI');
        }
    }

    public static function available(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    public function add(string $key, int $delta): void
    {
        $key = $this->prefix . $key;
        apcu_add($key, 0);
        apcu_inc($key, $delta);
    }

    public function set(string $key, int $value): void
    {
        apcu_store($this->prefix . $key, $value);
    }

    public function all(): array
    {
        $samples = [];
        $length = strlen($this->prefix);
        foreach (new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '/') as $entry) {
            if (is_array($entry) && is_string($entry['key'] ?? null) && is_int($entry['value'] ?? null)) {
                $samples[substr($entry['key'], $length)] = $entry['value'];
            }
        }

        return $samples;
    }
}
