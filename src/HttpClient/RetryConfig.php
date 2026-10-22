<?php

declare(strict_types=1);

namespace Keel\HttpClient;

/**
 * Retries of RetryingClient; set HttpClientConfig::$retry to enable them for the registered client.
 * Retries run inside the request that needs the answer, so the defaults keep the total wait short.
 */
final readonly class RetryConfig
{
    public const array IDEMPOTENT_METHODS = ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE', 'TRACE'];

    /** @var list<string> */
    public array $methods;

    /**
     * @param int          $maxAttempts       attempts in total, the first one included
     * @param float        $delay             seconds before the first retry, times $multiplier for each next one
     * @param float        $maxDelay          cap of one wait; a Retry-After above it returns the response instead of waiting
     * @param bool         $jitter            wait a random time between half and all of the delay, so clients that
     *                                        failed together do not come back together
     * @param list<int>    $statuses          responses worth another attempt; network failures always are
     * @param list<string> $methods           methods safe to repeat
     * @param string|null  $idempotencyHeader a request with this header is repeated whatever its method; null = never
     */
    public function __construct(
        public int $maxAttempts = 3,
        public float $delay = 0.1,
        public float $maxDelay = 2.0,
        public float $multiplier = 2.0,
        public bool $jitter = true,
        public array $statuses = [408, 429, 500, 502, 503, 504],
        array $methods = self::IDEMPOTENT_METHODS,
        public ?string $idempotencyHeader = 'Idempotency-Key',
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException(sprintf('RetryConfig maxAttempts must be at least 1, got %d', $maxAttempts));
        }
        if ($delay < 0 || $maxDelay < 0) {
            throw new \InvalidArgumentException('RetryConfig delays cannot be negative');
        }
        if ($multiplier < 1) {
            throw new \InvalidArgumentException(sprintf('RetryConfig multiplier must be at least 1, got %s', $multiplier));
        }
        $this->methods = array_map(strtoupper(...), $methods);
    }

    /**
     * Seconds before retry number $retry (1 for the first), without jitter.
     */
    public function backoff(int $retry): float
    {
        return min($this->maxDelay, $this->delay * $this->multiplier ** max(0, $retry - 1));
    }
}
