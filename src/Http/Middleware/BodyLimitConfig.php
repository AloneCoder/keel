<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

/**
 * Limits of request bodies for BodyLimitMiddleware. Register one in config/body_limit.php;
 * #[BodyLimit] on a route overrides either value for that route.
 */
final readonly class BodyLimitConfig
{
    /** Bytes; 0 means no size limit. */
    public int $maxBytes;

    /** @var list<string> lower-cased media types; empty means any */
    public array $contentTypes;

    /**
     * @param int|string   $maxBytes     bytes, or php.ini shorthand such as "512K", "10M", "1G"; 0 = no size limit
     * @param list<string> $contentTypes media types a request body may have: exact ("application/json"),
     *                                   a wildcard subtype ("image/*") or a suffix ("application/*+json");
     *                                   empty = any
     */
    public function __construct(int|string $maxBytes = '2M', array $contentTypes = [])
    {
        $this->maxBytes = self::parseSize($maxBytes);
        $types = [];
        foreach ($contentTypes as $type) {
            $type = strtolower(trim($type));
            if (preg_match('~^[a-z0-9!#$&^_.+\-]+/(\*|\*\+[a-z0-9!#$&^_.\-]+|[a-z0-9!#$&^_.+\-]+)$~', $type) !== 1) {
                throw new \InvalidArgumentException(sprintf('"%s" is not a media type pattern', $type));
            }
            $types[] = $type;
        }
        $this->contentTypes = $types;
    }

    /**
     * Bytes from an integer or "512K" / "10M" / "1G" (also "10MB").
     */
    public static function parseSize(int|string $size): int
    {
        if (is_int($size)) {
            $bytes = $size;
        } elseif (preg_match('/^\s*(\d+)\s*([KMG])?B?\s*$/i', $size, $parts) === 1) {
            $bytes = (int) $parts[1] * match (strtoupper($parts[2] ?? '')) {
                'K' => 1024,
                'M' => 1024 ** 2,
                'G' => 1024 ** 3,
                default => 1,
            };
        } else {
            throw new \InvalidArgumentException(sprintf('"%s" is not a size: use bytes or a number with K, M or G', $size));
        }
        if ($bytes < 0) {
            throw new \InvalidArgumentException('A body size limit must not be negative');
        }

        return $bytes;
    }

    /**
     * Whether a body of this media type (lower case, without parameters) is allowed.
     */
    public function accepts(string $mediaType): bool
    {
        if ($this->contentTypes === []) {
            return true;
        }
        [$type, $subtype] = array_pad(explode('/', $mediaType, 2), 2, '');
        foreach ($this->contentTypes as $pattern) {
            [$patternType, $patternSubtype] = explode('/', $pattern, 2);
            if ($patternType !== $type) {
                continue;
            }
            if ($patternSubtype === $subtype || $patternSubtype === '*') {
                return true;
            }
            if (str_starts_with($patternSubtype, '*+') && str_ends_with($subtype, substr($patternSubtype, 1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * This config with the values a #[BodyLimit] route option sets; null entries keep ours.
     *
     * @param array<mixed> $option
     */
    public function merge(array $option): self
    {
        $max = $option['maxBytes'] ?? null;
        $types = $option['contentTypes'] ?? null;

        return new self(
            is_int($max) ? $max : $this->maxBytes,
            is_array($types) ? array_values(array_filter($types, is_string(...))) : $this->contentTypes,
        );
    }
}
