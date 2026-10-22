<?php

declare(strict_types=1);

namespace Keel\HttpClient;

/**
 * Settings of CircuitBreaker; set HttpClientConfig::$circuitBreaker to put the registered client
 * behind one circuit per host.
 */
final readonly class CircuitBreakerConfig
{
    /**
     * @param int       $failures consecutive failures that open the circuit
     * @param int       $openFor  seconds an open circuit refuses calls before letting one probe through
     * @param int       $window   seconds after the last failure that the count is forgotten
     * @param list<int> $statuses responses that count as failures, besides network errors. 429 is not
     *                            among them by default: the host is up and asks to slow down
     * @param bool      $shared   keep the state in APCu when it is loaded, so the workers of a server
     *                            open and close the circuit together; false = each worker on its own
     */
    public function __construct(
        public int $failures = 5,
        public int $openFor = 30,
        public int $window = 60,
        public array $statuses = [500, 502, 503, 504],
        public bool $shared = true,
        public string $prefix = 'keel:circuit:',
    ) {
        if ($failures < 1) {
            throw new \InvalidArgumentException(sprintf('CircuitBreakerConfig failures must be at least 1, got %d', $failures));
        }
        if ($openFor < 1 || $window < 1) {
            throw new \InvalidArgumentException('CircuitBreakerConfig openFor and window must be at least one second');
        }
    }
}
