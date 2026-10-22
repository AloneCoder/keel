<?php

declare(strict_types=1);

namespace Keel\Idempotency;

/**
 * Settings of IdempotencyMiddleware. Register one in config/idempotency.php; #[Idempotent] on a
 * route overrides $required and $ttl for that route.
 */
final readonly class IdempotencyConfig
{
    /** @var list<string> */
    public array $methods;

    /**
     * @param string       $header   request header carrying the key
     * @param list<string> $methods  methods the key applies to; GET, PUT and DELETE are idempotent by definition
     * @param int          $ttl      seconds a stored response is replayed
     * @param int          $lockTtl  seconds a key stays locked while its first request runs; a request that takes
     *                               longer can be processed twice
     * @param bool         $required answer 400 to a request without the header
     * @param string       $prefix   cache key prefix; PSR-16 keys exclude {}()/\@:
     */
    public function __construct(
        public string $header = 'Idempotency-Key',
        array $methods = ['POST', 'PATCH'],
        public int $ttl = 86_400,
        public int $lockTtl = 60,
        public bool $required = false,
        public string $prefix = 'idempotency.',
    ) {
        if (strpbrk($prefix, '{}()/\\@:') !== false) {
            throw new \InvalidArgumentException(sprintf('Idempotency prefix "%s" contains a character PSR-16 reserves ({}()/\\@:)', $prefix));
        }
        if ($ttl < 1 || $lockTtl < 1) {
            throw new \InvalidArgumentException('Idempotency TTLs must be at least one second');
        }
        $this->methods = array_map(strtoupper(...), $methods);
    }

    /**
     * This config with what an #[Idempotent] route option sets.
     *
     * @param array<mixed> $option
     */
    public function merge(array $option): self
    {
        return new self(
            $this->header,
            $this->methods,
            is_int($option['ttl'] ?? null) ? $option['ttl'] : $this->ttl,
            $this->lockTtl,
            is_bool($option['required'] ?? null) ? $option['required'] : $this->required,
            $this->prefix,
        );
    }
}
