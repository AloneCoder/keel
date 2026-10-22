<?php

declare(strict_types=1);

namespace Keel\Runtime;

/**
 * When LoadSheddingMiddleware answers 503 instead of doing the work. Register one in
 * config/load_shedding.php; every signal is off until given a threshold.
 */
final readonly class LoadSheddingConfig
{
    /** Bytes; null = off. */
    public ?int $minAvailableMemory;

    /** @var list<string> */
    public array $except;

    /**
     * @param int|null        $maxInFlight        shed while more requests than this are being handled at once by the
     *                                            workers sharing APCu (a php-fpm pool, a FrankenPHP server); keep it
     *                                            below the worker count so the rest answer 503 fast instead of queueing
     * @param int|null        $maxQueueTime       shed a request that waited longer than this many milliseconds between
     *                                            the proxy and PHP, read from X-Request-Start / X-Queue-Start ("t=<time>"
     *                                            in seconds, milliseconds or microseconds, as nginx and Heroku set it)
     * @param float|null      $maxLoadAverage     shed while the one-minute load average of the host is above this
     * @param int|string|null $minAvailableMemory shed while less memory than this is available ("512M"): the cgroup
     *                                            limit minus its working set inside a container, MemAvailable otherwise
     *                                            (Linux only; the signal is skipped where it cannot be read)
     * @param int             $retryAfter         Retry-After seconds sent with the 503
     * @param list<string>    $except             path prefixes never shed: health probes, metrics
     */
    public function __construct(
        public ?int $maxInFlight = null,
        public ?int $maxQueueTime = null,
        public ?float $maxLoadAverage = null,
        int|string|null $minAvailableMemory = null,
        public int $retryAfter = 5,
        array $except = ['/health', '/metrics'],
    ) {
        if (($maxInFlight !== null && $maxInFlight < 1) || ($maxQueueTime !== null && $maxQueueTime < 1) || ($maxLoadAverage !== null && $maxLoadAverage <= 0)) {
            throw new \InvalidArgumentException('Load shedding thresholds must be positive');
        }
        if ($retryAfter < 0) {
            throw new \InvalidArgumentException('Retry-After must not be negative');
        }
        $this->minAvailableMemory = $minAvailableMemory === null ? null : self::bytes($minAvailableMemory);
        $this->except = array_map(static fn(string $prefix): string => '/' . trim($prefix, '/'), $except);
    }

    public function excepts(string $path): bool
    {
        foreach ($this->except as $prefix) {
            if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    private static function bytes(int|string $size): int
    {
        $bytes = is_int($size) ? $size : MemoryGuard::parseSize($size);
        if ($bytes <= 0) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a positive memory size', (string) $size));
        }

        return $bytes;
    }
}
