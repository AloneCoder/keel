<?php

declare(strict_types=1);

namespace Keel\Auth\Jwt;

/**
 * Compact JWS encoding: base64url helpers, parsing without verification, and signing for
 * HS256/384/512, RS256/384/512, ES256/384/512 and EdDSA (Ed25519). Verification lives in
 * JwtVerifier; Jwt::encode() is for tests, internal services and issuing your own tokens.
 */
final class Jwt
{
    /** Algorithm => [key type, hash or curve]. */
    public const array ALGORITHMS = [
        'HS256' => ['oct', 'sha256'],
        'HS384' => ['oct', 'sha384'],
        'HS512' => ['oct', 'sha512'],
        'RS256' => ['RSA', 'sha256'],
        'RS384' => ['RSA', 'sha384'],
        'RS512' => ['RSA', 'sha512'],
        'ES256' => ['EC', 'P-256'],
        'ES384' => ['EC', 'P-384'],
        'ES512' => ['EC', 'P-521'],
        'EdDSA' => ['OKP', 'Ed25519'],
    ];

    /** Curve => [hash, coordinate length in bytes]. */
    public const array CURVES = [
        'P-256' => ['sha256', 32],
        'P-384' => ['sha384', 48],
        'P-521' => ['sha512', 66],
    ];

    /** Tokens longer than this are refused before any parsing. */
    public const int MAX_LENGTH = 16_384;

    private function __construct() {}

    /**
     * Signs $claims. $key is the HMAC secret, a PEM private key (or an OpenSSL key) for RS and ES,
     * or the 64-byte sodium secret key (sodium_crypto_sign_secretkey()) for EdDSA.
     *
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $header extra header fields
     */
    public static function encode(array $claims, #[\SensitiveParameter] string|\OpenSSLAsymmetricKey $key, string $algorithm = 'HS256', ?string $keyId = null, array $header = []): string
    {
        if (!isset(self::ALGORITHMS[$algorithm])) {
            throw new \InvalidArgumentException(sprintf('Unsupported JWT algorithm "%s"', $algorithm));
        }
        $header = ['typ' => 'JWT', 'alg' => $algorithm] + ($keyId === null ? [] : ['kid' => $keyId]) + $header;
        $input = self::base64UrlEncode(json_encode($header, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES))
            . '.' . self::base64UrlEncode(json_encode($claims, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

        return $input . '.' . self::base64UrlEncode(self::sign($input, $key, $algorithm));
    }

    /**
     * Splits and decodes a compact token without checking anything but its shape.
     *
     * @return array{header: array<string, mixed>, claims: array<string, mixed>, input: string, signature: string}
     *
     * @throws JwtException when it is not a well-formed JWS with JSON object header and claims
     */
    public static function parse(string $token): array
    {
        if (strlen($token) > self::MAX_LENGTH) {
            throw new JwtException('Token too long');
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new JwtException('A JWT has three dot-separated parts');
        }
        [$header, $claims, $signature] = $parts;
        $decodedHeader = self::json(self::base64UrlDecode($header), 'header');
        $decodedClaims = self::json(self::base64UrlDecode($claims), 'claims');

        return ['header' => $decodedHeader, 'claims' => $decodedClaims, 'input' => $header . '.' . $claims, 'signature' => self::base64UrlDecode($signature)];
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @throws JwtException on characters outside the base64url alphabet
     */
    public static function base64UrlDecode(string $data): string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/D', $data) !== 1) {
            throw new JwtException('Invalid base64url data');
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) {
            throw new JwtException('Invalid base64url data');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $json, string $part): array
    {
        try {
            $decoded = json_decode($json, true, 32, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new JwtException(sprintf('JWT %s is not JSON', $part), previous: $e);
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new JwtException(sprintf('JWT %s must be a JSON object', $part));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function sign(string $input, #[\SensitiveParameter] string|\OpenSSLAsymmetricKey $key, string $algorithm): string
    {
        [$type, $parameter] = self::ALGORITHMS[$algorithm];
        if ($type === 'oct') {
            if (!is_string($key) || $key === '') {
                throw new \InvalidArgumentException('An HMAC JWT needs a non-empty secret');
            }

            return hash_hmac($parameter, $input, $key, true);
        }
        if ($type === 'OKP') {
            if (!is_string($key) || strlen($key) !== \SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                throw new \InvalidArgumentException('An EdDSA JWT needs the 64-byte sodium secret key');
            }

            return sodium_crypto_sign_detached($input, $key);
        }

        $private = is_string($key) ? openssl_pkey_get_private($key) : $key;
        if ($private === false) {
            throw new \InvalidArgumentException('Cannot read the private key: ' . (string) openssl_error_string());
        }
        [$hash, $length] = $type === 'EC' ? self::CURVES[$parameter] ?? throw new \LogicException('Unknown curve ' . $parameter) : [$parameter, 0];
        if (!openssl_sign($input, $signature, $private, $hash) || !is_string($signature)) {
            throw new \RuntimeException('Signing failed: ' . (string) openssl_error_string());
        }

        return $type === 'EC' ? Der::ecdsaToRaw($signature, $length) : $signature;
    }
}
