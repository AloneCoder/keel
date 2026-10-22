<?php

declare(strict_types=1);

namespace Keel\Session;

/**
 * HMAC-SHA256 signing of cookie values: "payload.signature", url-safe base64. Signed values
 * are readable by the client but cannot be altered; keep secrets out of them.
 *
 * Give every use its own $purpose ("session", "remember-me", "signed-url"): a value signed
 * for one purpose then fails verification under another, even with the same secret. The default
 * (empty) purpose keeps values signed by earlier versions valid.
 */
final readonly class CookieSigner
{
    private string $key;

    public function __construct(private string $secret, string $purpose = '')
    {
        $this->key = $purpose === '' ? $this->secret : hash_hmac('sha256', $purpose, $this->secret, true);
    }

    public function sign(string $payload): string
    {
        $encoded = self::encode($payload);

        return $encoded . '.' . $this->signature($encoded);
    }

    /**
     * The signature alone (url-safe base64, 43 characters), for a payload that travels separately,
     * such as the path and query of a signed URL. Compare it with hash_equals().
     */
    public function signature(string $payload): string
    {
        return self::encode(hash_hmac('sha256', $payload, $this->key, true));
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
        if ($signature === null || !hash_equals(hash_hmac('sha256', $encoded, $this->key, true), $signature)) {
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
