<?php

declare(strict_types=1);

namespace Keel\Cache;

/**
 * Settings of the server-side output cache (#[OutputCache]). Register one in config/output_cache.php.
 */
final readonly class OutputCacheConfig
{
    /**
     * @param string      $prefix       cache key prefix; PSR-16 keys exclude {}()/\@:
     * @param string|null $header       response header reporting HIT or MISS; null sends none
     * @param int         $maxBodyBytes responses with a larger body are not stored
     */
    public function __construct(
        public string $prefix = 'output.',
        public ?string $header = 'X-Cache',
        public int $maxBodyBytes = 1_048_576,
    ) {
        if (strpbrk($prefix, '{}()/\\@:') !== false) {
            throw new \InvalidArgumentException(sprintf('Output cache prefix "%s" contains a character PSR-16 reserves ({}()/\\@:)', $prefix));
        }
        if ($maxBodyBytes < 1) {
            throw new \InvalidArgumentException('OutputCacheConfig::$maxBodyBytes must be positive');
        }
    }
}
