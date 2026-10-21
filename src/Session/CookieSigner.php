<?php

declare(strict_types=1);

namespace Keel\Session;

/**
 * HMAC-SHA256 signing of cookie values: "payload.signature", url-safe base64. Signed values
 * are readable by the client but cannot be altered; keep secrets out of them.
 */
final readonly class CookieSigner
{
    public function __construct(private string $secret) {}

    public function sign(string $payload): string
    {
        $encoded = self::encode($payload);

        return $encoded . '.' . self::encode(hash_hmac('sha256', $encoded, $this->secret, true));
    }

    /**
     * @return string|null the payload, or null when the signature does not match
     */
    public function verify(string $value): ?string
    {
        $dot = strrpos($value, '.');
        if ($dot === false) {
            return null;
        }

        $encoded = substr($value, 0, $dot);
        $signature = self::decode(substr($value, $dot + 1));
        if ($signature === null || !hash_equals(hash_hmac('sha256', $encoded, $this->secret, true), $signature)) {
            return null;
        }

        return self::decode($encoded);
    }

    private static function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private static function decode(string $text): ?string
    {
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
