<?php

declare(strict_types=1);

namespace Keel\Runtime;

use Keel\Http\Exception\ServiceUnavailableException;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Answers 503 with Retry-After, before any work, while the server is overloaded: too many
 * requests in flight across the workers, a request that already queued too long in front of
 * PHP, a high load average, or too little memory left. Under overload a fast 503 for some
 * requests keeps the others answerable, where doing all the work would time them all out.
 * The sibling of MemoryGuard: that one recycles a bloated worker, this one protects the server.
 *
 * Place it early in HttpConfig, after HealthMiddleware and MetricsMiddleware (or keep their
 * paths in $except), so probes and scrapes are never shed.
 */
final class LoadSheddingMiddleware implements MiddlewareInterface
{
    private const string CGROUP = '/sys/fs/cgroup';

    private readonly LoadSheddingConfig $config;

    private readonly InFlightCounter $counter;

    /** @var array{0: int, 1: int|null}|null second and value of the last memory reading */
    private ?array $memory = null;

    /**
     * @param (\Closure(): (float|null))|null $loadAverage     replacement for the one-minute sys_getloadavg(), used in tests
     * @param (\Closure(): (int|null))|null   $availableMemory replacement for the cgroup / MemAvailable reading, used in tests
     */
    public function __construct(
        ?LoadSheddingConfig $config = null,
        ?InFlightCounter $counter = null,
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?\Closure $loadAverage = null,
        private readonly ?\Closure $availableMemory = null,
    ) {
        $this->config = $config ?? new LoadSheddingConfig();
        $this->counter = $counter ?? (ApcuInFlightCounter::available() ? new ApcuInFlightCounter() : new LocalInFlightCounter());
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->config->excepts($request->getUri()->getPath())) {
            return $handler->handle($request);
        }

        $reason = $this->pressure($request);
        if ($reason !== null) {
            $this->shed($reason);
        }
        if ($this->config->maxInFlight === null) {
            return $handler->handle($request);
        }

        $inFlight = $this->counter->enter();
        try {
            if ($inFlight > $this->config->maxInFlight) {
                $this->shed(sprintf('%d requests in flight, limit %d', $inFlight, $this->config->maxInFlight));
            }

            return $handler->handle($request);
        } finally {
            $this->counter->leave();
        }
    }

    /**
     * Why the request should be shed by the signals that need no counter, or null.
     */
    private function pressure(ServerRequestInterface $request): ?string
    {
        if ($this->config->maxQueueTime !== null) {
            $queued = $this->queueTime($request);
            if ($queued !== null && $queued > $this->config->maxQueueTime) {
                return sprintf('queued %d ms, limit %d ms', $queued, $this->config->maxQueueTime);
            }
        }
        if ($this->config->maxLoadAverage !== null) {
            $load = $this->loadAverage !== null ? ($this->loadAverage)() : self::systemLoad();
            if ($load !== null && $load > $this->config->maxLoadAverage) {
                return sprintf('load average %.2f, limit %.2f', $load, $this->config->maxLoadAverage);
            }
        }
        if ($this->config->minAvailableMemory !== null) {
            $available = $this->availableMemory();
            if ($available !== null && $available < $this->config->minAvailableMemory) {
                return sprintf('%s available, minimum %s', MemoryGuard::format($available), MemoryGuard::format($this->config->minAvailableMemory));
            }
        }

        return null;
    }

    private function shed(string $reason): never
    {
        $this->logger?->info('Load shed: ' . $reason);

        throw new ServiceUnavailableException('The server is overloaded; retry later', ['Retry-After' => (string) $this->config->retryAfter]);
    }

    /**
     * Milliseconds between the proxy's timestamp and now; the unit of the timestamp is told by its size.
     */
    private function queueTime(ServerRequestInterface $request): ?int
    {
        $header = $request->getHeaderLine('X-Request-Start');
        $header = $header !== '' ? $header : $request->getHeaderLine('X-Queue-Start');
        if (preg_match('/^\s*(?:t=)?(\d{1,20}(?:\.\d+)?)\s*$/', $header, $parts) !== 1) {
            return null;
        }
        $stamp = (float) $parts[1];
        $seconds = match (true) {
            $stamp > 1e15 => $stamp / 1e6,
            $stamp > 1e12 => $stamp / 1e3,
            default => $stamp,
        };
        $now = $this->clock !== null ? (float) $this->clock->now()->format('U.u') : microtime(true);

        return max(0, (int) round(($now - $seconds) * 1000));
    }

    private static function systemLoad(): ?float
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

        return is_array($load) ? $load[0] : null;
    }

    /**
     * Read at most once a second per worker.
     */
    private function availableMemory(): ?int
    {
        if ($this->availableMemory !== null) {
            return ($this->availableMemory)();
        }
        $second = time();
        if ($this->memory === null || $this->memory[0] !== $second) {
            $this->memory = [$second, self::cgroupAvailable() ?? self::memAvailable()];
        }

        return $this->memory[1];
    }

    /**
     * cgroup v2 limit minus the working set (usage without inactive page cache), as the kubelet counts it.
     */
    private static function cgroupAvailable(): ?int
    {
        $max = @file_get_contents(self::CGROUP . '/memory.max');
        $current = @file_get_contents(self::CGROUP . '/memory.current');
        if (!is_string($max) || !is_string($current) || !ctype_digit(trim($max)) || !ctype_digit(trim($current))) {
            return null;
        }
        $stat = (string) @file_get_contents(self::CGROUP . '/memory.stat');
        $inactive = preg_match('/^inactive_file (\d+)$/m', $stat, $parts) === 1 ? (int) $parts[1] : 0;

        return max(0, (int) trim($max) - max(0, (int) trim($current) - $inactive));
    }

    private static function memAvailable(): ?int
    {
        $info = @file_get_contents('/proc/meminfo');

        return is_string($info) && preg_match('/^MemAvailable:\s+(\d+) kB$/m', $info, $parts) === 1 ? (int) $parts[1] * 1024 : null;
    }
}
