<?php

declare(strict_types=1);

namespace Keel\HttpClient;

use Keel\Cache\ApcuCache;
use Keel\Cache\ArrayCache;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Circuits by name (CircuitBreakerClient uses one per host). A circuit opens after
 * CircuitBreakerConfig::$failures consecutive failures and refuses calls for $openFor seconds;
 * then the first call is let through as a probe while the others are refused for another
 * $openFor, and the probe's outcome closes the circuit or opens it again. A success closes it.
 *
 * The state lives in a PSR-16 store: APCu by default when loaded, so every worker of a server
 * sees the circuit open after the same few failures instead of each paying for its own; the
 * counting is read-modify-write, so concurrent failures may be counted approximately. Without
 * APCu (or with $shared off) the state is per worker.
 */
final class CircuitBreaker
{
    private readonly CacheInterface $store;

    public function __construct(
        public readonly CircuitBreakerConfig $config = new CircuitBreakerConfig(),
        ?CacheInterface $store = null,
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->store = $store ?? ($config->shared && ApcuCache::available() ? new ApcuCache($config->prefix) : new ArrayCache());
    }

    /**
     * Whether a call may go out now. In the half-open state the call that gets true is the probe:
     * report its outcome with success() or failure().
     */
    public function allow(string $circuit): bool
    {
        [$failures, $openUntil] = $this->read($circuit);
        if ($openUntil === null) {
            return true;
        }
        $now = $this->now();
        if ($now < $openUntil) {
            return false;
        }
        $this->write($circuit, $failures, $now + $this->config->openFor);

        return true;
    }

    public function success(string $circuit): void
    {
        if ($this->store->get($this->key($circuit)) !== null) {
            $this->store->delete($this->key($circuit));
        }
    }

    public function failure(string $circuit): void
    {
        [$failures, $openUntil] = $this->read($circuit);
        $failures++;
        if ($openUntil === null && $failures < $this->config->failures) {
            $this->write($circuit, $failures, null);

            return;
        }
        if ($openUntil === null) {
            $this->logger?->warning('Circuit {circuit} opened for {seconds} s after {failures} consecutive failures', [
                'circuit' => $circuit,
                'seconds' => $this->config->openFor,
                'failures' => $failures,
            ]);
        }
        $this->write($circuit, $failures, $this->now() + $this->config->openFor);
    }

    public function state(string $circuit): CircuitState
    {
        [, $openUntil] = $this->read($circuit);

        return match (true) {
            $openUntil === null => CircuitState::Closed,
            $this->now() < $openUntil => CircuitState::Open,
            default => CircuitState::HalfOpen,
        };
    }

    /**
     * Closes the circuit and forgets its failures.
     */
    public function reset(string $circuit): void
    {
        $this->store->delete($this->key($circuit));
    }

    /**
     * @return array{0: int, 1: float|null} consecutive failures, open until (unix time)
     */
    private function read(string $circuit): array
    {
        $state = $this->store->get($this->key($circuit));
        if (!is_array($state) || !is_int($state[0] ?? null)) {
            return [0, null];
        }

        return [$state[0], is_float($state[1] ?? null) ? $state[1] : null];
    }

    private function write(string $circuit, int $failures, ?float $openUntil): void
    {
        $ttl = $this->config->window + ($openUntil === null ? 0 : $this->config->openFor);
        $this->store->set($this->key($circuit), [$failures, $openUntil], $ttl);
    }

    private function key(string $circuit): string
    {
        return 'circuit.' . hash('xxh128', $circuit);
    }

    private function now(): float
    {
        return $this->clock !== null ? (float) $this->clock->now()->format('U.u') : microtime(true);
    }
}
