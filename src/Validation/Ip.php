<?php

declare(strict_types=1);

namespace Keel\Validation;

/**
 * An IP address: any version by default, or IPv4 / IPv6 only with $version.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Ip implements Rule
{
    /**
     * @param int|null $version 4, 6 or null for either
     */
    public function __construct(public ?int $version = null)
    {
        if ($version !== null && $version !== 4 && $version !== 6) {
            throw new \InvalidArgumentException('Ip version must be 4, 6 or null');
        }
    }

    public function check(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $flags = $this->version === 4 ? \FILTER_FLAG_IPV4 : ($this->version === 6 ? \FILTER_FLAG_IPV6 : 0);
        if (filter_var($value, \FILTER_VALIDATE_IP, $flags) !== false) {
            return null;
        }

        return $this->version === null ? 'must be an IP address' : sprintf('must be an IPv%d address', $this->version);
    }
}
