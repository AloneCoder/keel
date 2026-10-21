<?php

declare(strict_types=1);

namespace Keel\Runtime;

use Psr\Log\LoggerInterface;

/**
 * Stops a worker before a slow leak turns into a fatal "Allowed memory size exhausted" in the
 * middle of a request. Checked by the worker runtimes after every request: once the memory the
 * process holds (memory_get_usage(true)) passes the limit the loop ends, and FrankenPHP or
 * RoadRunner start a fresh worker.
 *
 * The limit comes from KEEL_MAX_MEMORY (bytes, or php.ini shorthand such as "256M"); by default
 * it is 90% of memory_limit, and it is off when memory_limit is -1 or KEEL_MAX_MEMORY is 0.
 */
final class MemoryGuard
{
    public const string ENV = 'KEEL_MAX_MEMORY';

    private const float DEFAULT_SHARE = 0.9;

    /**
     * @param int $limit bytes; 0 or less disables the guard
     */
    public function __construct(private readonly int $limit, private readonly ?LoggerInterface $logger = null) {}

    /**
     * @param array<string, mixed>|null $server defaults to $_SERVER, then getenv()
     */
    public static function fromEnvironment(?array $server = null, ?LoggerInterface $logger = null): self
    {
        $server ??= $_SERVER;
        $configured = $server[self::ENV] ?? getenv(self::ENV);
        if (is_string($configured) && trim($configured) !== '') {
            return new self(self::parseSize($configured), $logger);
        }

        $memoryLimit = self::parseSize((string) ini_get('memory_limit'));

        return new self($memoryLimit > 0 ? (int) ($memoryLimit * self::DEFAULT_SHARE) : 0, $logger);
    }

    /**
     * php.ini shorthand: "128M", "1G", "262144"; -1 and anything unparsable mean "no limit" (0).
     */
    public static function parseSize(string $value): int
    {
        $value = trim($value);
        if (preg_match('/^(\d+)\s*([KMG])?B?$/i', $value, $parts) !== 1) {
            return 0;
        }

        $bytes = (int) $parts[1];
        $bytes *= match (strtoupper($parts[2] ?? '')) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };

        return $bytes;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function enabled(): bool
    {
        return $this->limit > 0;
    }

    public function usage(): int
    {
        return memory_get_usage(true);
    }

    /**
     * True once the process holds more than the limit; logs a warning with the numbers so the
     * restart is visible in the worker log.
     */
    public function exceeded(int $requestsHandled = 0): bool
    {
        if ($this->limit <= 0) {
            return false;
        }

        $usage = $this->usage();
        if ($usage <= $this->limit) {
            return false;
        }

        $this->logger?->warning(sprintf(
            'Worker memory %s exceeds the limit of %s after %d request(s); stopping so the server starts a fresh worker',
            self::format($usage),
            self::format($this->limit),
            $requestsHandled,
        ), ['memory_usage' => $usage, 'memory_limit' => $this->limit, 'requests' => $requestsHandled]);

        return true;
    }

    public static function format(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 ** 3 => sprintf('%.1f GB', $bytes / 1024 ** 3),
            $bytes >= 1024 ** 2 => sprintf('%.1f MB', $bytes / 1024 ** 2),
            $bytes >= 1024 => sprintf('%.1f KB', $bytes / 1024),
            default => $bytes . ' B',
        };
    }
}
