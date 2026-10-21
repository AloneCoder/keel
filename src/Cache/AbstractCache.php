<?php

declare(strict_types=1);

namespace Keel\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 plumbing shared by the built-in drivers: key validation, TTL normalisation,
 * the *Multiple methods.
 */
abstract class AbstractCache implements CacheInterface
{
    public function __construct(protected readonly ?int $defaultTtl = null) {}

    /**
     * @param iterable<string> $keys
     *
     * @return array<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];
        foreach ($keys as $key) {
            // PHP turns numeric-string array keys into ints; the driver always sees a string.
            $values[$key] = $this->get((string) $key, $default);
        }

        return $values;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->delete((string) $key) && $ok;
        }

        return $ok;
    }

    protected static function validate(string $key): string
    {
        if ($key === '' || strlen($key) > 256 || strpbrk($key, '{}()/\\@:') !== false) {
            throw new InvalidKeyException(sprintf('Invalid cache key "%s": 1-256 characters, none of {}()/\\@:', $key));
        }

        return $key;
    }

    /**
     * Seconds until expiry: null = forever, 0 or less = already expired.
     */
    protected function seconds(int|\DateInterval|null $ttl): ?int
    {
        if ($ttl instanceof \DateInterval) {
            $now = new \DateTimeImmutable();

            return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }

        return $ttl ?? $this->defaultTtl;
    }
}
