<?php

declare(strict_types=1);

namespace Keel\Auth\Jwt;

/**
 * A key that verifies JWT signatures: an HMAC secret, an RSA or EC public key, or an Ed25519
 * public key. A key only verifies the algorithms of its own type (an RSA key never verifies
 * HS256, which defeats the classic algorithm-confusion attack) and, when it names an algorithm
 * (JWK "alg"), only that one.
 */
final class JwtKey
{
    private const int MIN_RSA_BITS = 2048;

    private const int MIN_SECRET_BYTES = 32;

    private ?\OpenSSLAsymmetricKey $openssl = null;

    /**
     * @param string $type "oct", "RSA", "EC" or "OKP"
     */
    private function __construct(
        public readonly string $type,
        #[\SensitiveParameter]
        private readonly string $material,
        public readonly ?string $id = null,
        public readonly ?string $algorithm = null,
        public readonly ?string $curve = null,
    ) {
        if ($algorithm !== null && !$this->supportsType($algorithm)) {
            throw new \InvalidArgumentException(sprintf('A %s key cannot verify %s', $type, $algorithm));
        }
    }

    /**
     * An HMAC secret (HS256/384/512) of at least 32 bytes.
     */
    public static function secret(#[\SensitiveParameter] string $secret, ?string $id = null, ?string $algorithm = null): self
    {
        if (strlen($secret) < self::MIN_SECRET_BYTES) {
            throw new \InvalidArgumentException(sprintf('An HMAC JWT secret needs at least %d bytes', self::MIN_SECRET_BYTES));
        }

        return new self('oct', $secret, $id, $algorithm);
    }

    /**
     * A "BEGIN PUBLIC KEY" PEM: RSA (2048 bits or more), EC on P-256, P-384 or P-521, or Ed25519.
     */
    public static function publicKey(string $pem, ?string $id = null, ?string $algorithm = null): self
    {
        $ed25519 = Der::ed25519FromPem($pem);
        if ($ed25519 !== null) {
            return new self('OKP', $ed25519, $id, $algorithm, 'Ed25519');
        }

        $key = openssl_pkey_get_public($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false) {
            throw new \InvalidArgumentException('Cannot read the public key: ' . (string) openssl_error_string());
        }
        $type = $details['type'] ?? null;
        if ($type === \OPENSSL_KEYTYPE_RSA) {
            $bits = $details['bits'] ?? 0;
            if (!is_int($bits) || $bits < self::MIN_RSA_BITS) {
                throw new \InvalidArgumentException(sprintf('RSA keys for JWT need at least %d bits', self::MIN_RSA_BITS));
            }

            return new self('RSA', $pem, $id, $algorithm);
        }
        if ($type === \OPENSSL_KEYTYPE_EC) {
            $ec = $details['ec'] ?? null;
            $curve = match (is_array($ec) ? ($ec['curve_name'] ?? null) : null) {
                'prime256v1' => 'P-256',
                'secp384r1' => 'P-384',
                'secp521r1' => 'P-521',
                default => throw new \InvalidArgumentException('EC keys for JWT must be on P-256, P-384 or P-521'),
            };

            return new self('EC', $pem, $id, $algorithm, $curve);
        }

        throw new \InvalidArgumentException('JWT public keys must be RSA, EC or Ed25519');
    }

    /**
     * A key from a JSON Web Key; null for keys this library does not verify with (encryption
     * keys, other curves, private-only material).
     *
     * @param array<mixed> $jwk
     */
    public static function fromJwk(array $jwk): ?self
    {
        $string = static fn(string $name): ?string => is_string($jwk[$name] ?? null) ? $jwk[$name] : null;
        $use = $string('use');
        if ($use !== null && $use !== 'sig') {
            return null;
        }
        $id = $string('kid');
        $algorithm = $string('alg');
        if ($algorithm !== null && !isset(Jwt::ALGORITHMS[$algorithm])) {
            return null;
        }

        try {
            return match ($string('kty')) {
                'RSA' => ($n = $string('n')) !== null && ($e = $string('e')) !== null
                    ? self::publicKey(Der::rsaPublicKey(Jwt::base64UrlDecode($n), Jwt::base64UrlDecode($e)), $id, $algorithm)
                    : null,
                'EC' => ($crv = $string('crv')) !== null && isset(Jwt::CURVES[$crv]) && ($x = $string('x')) !== null && ($y = $string('y')) !== null
                    ? new self('EC', Der::ecPublicKey($crv, Jwt::base64UrlDecode($x), Jwt::base64UrlDecode($y)), $id, $algorithm, $crv)
                    : null,
                'OKP' => $string('crv') === 'Ed25519' && ($x = $string('x')) !== null && strlen($raw = Jwt::base64UrlDecode($x)) === \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                    ? new self('OKP', $raw, $id, $algorithm, 'Ed25519')
                    : null,
                'oct' => ($k = $string('k')) !== null ? self::secret(Jwt::base64UrlDecode($k), $id, $algorithm) : null,
                default => null,
            };
        } catch (JwtException|\InvalidArgumentException) {
            return null;
        }
    }

    public function supports(string $algorithm): bool
    {
        return ($this->algorithm === null || $this->algorithm === $algorithm) && $this->supportsType($algorithm);
    }

    public function verify(string $input, string $signature, string $algorithm): bool
    {
        if (!$this->supports($algorithm)) {
            return false;
        }
        [, $parameter] = Jwt::ALGORITHMS[$algorithm];

        return match ($this->type) {
            'oct' => hash_equals(hash_hmac($parameter, $input, $this->material, true), $signature),
            'OKP' => strlen($signature) === \SODIUM_CRYPTO_SIGN_BYTES && $this->material !== '' && sodium_crypto_sign_verify_detached($signature, $input, $this->material),
            'RSA' => openssl_verify($input, $signature, $this->openssl(), $parameter) === 1,
            'EC' => $this->verifyEcdsa($input, $signature, $parameter),
            default => false,
        };
    }

    private function verifyEcdsa(string $input, string $signature, string $curve): bool
    {
        [$hash, $length] = Jwt::CURVES[$curve];
        try {
            $der = Der::rawToEcdsa($signature, $length);
        } catch (JwtException) {
            return false;
        }

        return openssl_verify($input, $der, $this->openssl(), $hash) === 1;
    }

    private function supportsType(string $algorithm): bool
    {
        $spec = Jwt::ALGORITHMS[$algorithm] ?? null;
        if ($spec === null || $spec[0] !== $this->type) {
            return false;
        }

        return !in_array($this->type, ['EC', 'OKP'], true) || $spec[1] === $this->curve;
    }

    private function openssl(): \OpenSSLAsymmetricKey
    {
        if ($this->openssl === null) {
            $key = openssl_pkey_get_public($this->material);
            if ($key === false) {
                throw new \RuntimeException('Cannot read the public key: ' . (string) openssl_error_string());
            }
            $this->openssl = $key;
        }

        return $this->openssl;
    }
}
