<?php

declare(strict_types=1);

namespace Keel\Auth\Jwt;

/**
 * The bits of ASN.1 DER that JWT needs: ECDSA signatures between JOSE's raw R||S and OpenSSL's
 * DER sequence, and PEM public keys built from JWK parameters.
 *
 * @internal
 */
final class Der
{
    private const string OID_RSA = "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01";

    private const string OID_EC = "\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01";

    private const array OID_CURVES = [
        'P-256' => "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07",
        'P-384' => "\x06\x05\x2B\x81\x04\x00\x22",
        'P-521' => "\x06\x05\x2B\x81\x04\x00\x23",
    ];

    /** SubjectPublicKeyInfo prefix of an Ed25519 key; the 32 key bytes follow. */
    private const string ED25519_PREFIX = "\x30\x2A\x30\x05\x06\x03\x2B\x65\x70\x03\x21\x00";

    private function __construct() {}

    public static function ecdsaToRaw(string $der, int $length): string
    {
        $offset = 0;
        if (self::tag($der, $offset) !== 0x30) {
            throw new JwtException('ECDSA signature is not a DER sequence');
        }
        self::length($der, $offset);
        $raw = '';
        foreach (['r', 's'] as $part) {
            if (self::tag($der, $offset) !== 0x02) {
                throw new JwtException(sprintf('ECDSA signature %s is not an integer', $part));
            }
            $size = self::length($der, $offset);
            $integer = ltrim(substr($der, $offset, $size), "\x00");
            $offset += $size;
            if (strlen($integer) > $length) {
                throw new JwtException('ECDSA signature integer too long for the curve');
            }
            $raw .= str_pad($integer, $length, "\x00", \STR_PAD_LEFT);
        }

        return $raw;
    }

    public static function rawToEcdsa(string $raw, int $length): string
    {
        if (strlen($raw) !== 2 * $length) {
            throw new JwtException(sprintf('ECDSA signature must be %d bytes', 2 * $length));
        }

        return self::sequence(self::integer(substr($raw, 0, $length)) . self::integer(substr($raw, $length)));
    }

    public static function rsaPublicKey(string $modulus, string $exponent): string
    {
        $key = self::sequence(self::integer($modulus) . self::integer($exponent));

        return self::pem(self::sequence(self::sequence(self::OID_RSA . "\x05\x00") . self::bitString($key)));
    }

    public static function ecPublicKey(string $curve, string $x, string $y): string
    {
        $oid = self::OID_CURVES[$curve] ?? throw new JwtException(sprintf('Unsupported curve "%s"', $curve));
        $length = Jwt::CURVES[$curve][1];
        if (strlen($x) !== $length || strlen($y) !== $length) {
            throw new JwtException(sprintf('%s coordinates must be %d bytes', $curve, $length));
        }

        return self::pem(self::sequence(self::sequence(self::OID_EC . $oid) . self::bitString("\x04" . $x . $y)));
    }

    /**
     * The raw 32-byte key of an Ed25519 "BEGIN PUBLIC KEY" PEM, or null for any other key.
     */
    public static function ed25519FromPem(string $pem): ?string
    {
        if (preg_match('/-----BEGIN PUBLIC KEY-----(.+?)-----END PUBLIC KEY-----/s', $pem, $parts) !== 1) {
            return null;
        }
        $der = base64_decode((string) preg_replace('/\s+/', '', $parts[1]), true);
        if ($der === false || strlen($der) !== strlen(self::ED25519_PREFIX) + 32 || !str_starts_with($der, self::ED25519_PREFIX)) {
            return null;
        }

        return substr($der, strlen(self::ED25519_PREFIX));
    }

    private static function tag(string $der, int &$offset): int
    {
        if (!isset($der[$offset])) {
            throw new JwtException('Truncated DER');
        }

        return ord($der[$offset++]);
    }

    private static function length(string $der, int &$offset): int
    {
        $first = self::tag($der, $offset);
        if ($first < 0x80) {
            $length = $first;
        } else {
            $bytes = $first & 0x7F;
            if ($bytes < 1 || $bytes > 2) {
                throw new JwtException('Unsupported DER length');
            }
            $length = 0;
            for ($i = 0; $i < $bytes; $i++) {
                $length = ($length << 8) | self::tag($der, $offset);
            }
        }
        if ($offset + $length > strlen($der)) {
            throw new JwtException('Truncated DER');
        }

        return $length;
    }

    private static function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::encodeLength(strlen($bytes)) . $bytes;
    }

    private static function sequence(string $content): string
    {
        return "\x30" . self::encodeLength(strlen($content)) . $content;
    }

    private static function bitString(string $content): string
    {
        return "\x03" . self::encodeLength(strlen($content) + 1) . "\x00" . $content;
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
