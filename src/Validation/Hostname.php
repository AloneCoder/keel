<?php

declare(strict_types=1);

namespace Keel\Validation;

/**
 * A DNS hostname (RFC 1123 labels, at most 253 characters); IP literals are refused. With
 * $requireTld a single label such as "localhost" is refused too.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Hostname implements Rule
{
    public function __construct(public bool $requireTld = true, public string $message = 'must be a hostname') {}

    public function check(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $host = rtrim($value, '.');
        if ($host === '' || strlen($host) > 253 || filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            return $this->message;
        }
        $labels = explode('.', $host);
        if ($this->requireTld && count($labels) < 2) {
            return $this->message;
        }
        foreach ($labels as $label) {
            if (preg_match('/^(?!-)[A-Za-z0-9-]{1,63}(?<!-)$/', $label) !== 1) {
                return $this->message;
            }
        }
        if ($this->requireTld && preg_match('/^[A-Za-z]{2,63}$/', end($labels)) !== 1) {
            return $this->message;
        }

        return null;
    }
}
