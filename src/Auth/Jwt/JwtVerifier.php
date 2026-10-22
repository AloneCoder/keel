<?php

declare(strict_types=1);

namespace Keel\Auth\Jwt;

use Psr\Clock\ClockInterface;

/**
 * Verifies a compact JWS and returns its claims: the algorithm must be one the config accepts,
 * the signature must check out against a key of the matching type (and key id), "crit" headers
 * are refused, and exp, nbf, iat, iss, aud and the required claims are validated.
 */
final class JwtVerifier
{
    public function __construct(
        private readonly JwtConfig $config,
        private readonly KeySet $keys,
        private readonly ?ClockInterface $clock = null,
    ) {}

    /**
     * @return array<string, mixed> the claims
     *
     * @throws JwtException when the token is not to be trusted; the message says why
     */
    public function verify(string $token): array
    {
        $parsed = Jwt::parse($token);
        $header = $parsed['header'];

        $algorithm = $header['alg'] ?? null;
        if (!is_string($algorithm) || !in_array($algorithm, $this->config->algorithms, true)) {
            throw new JwtException(sprintf('Algorithm "%s" is not accepted', is_string($algorithm) ? $algorithm : get_debug_type($algorithm)));
        }
        if (array_key_exists('crit', $header)) {
            throw new JwtException('Critical header extensions are not supported');
        }
        $keyId = $header['kid'] ?? null;
        if ($keyId !== null && !is_string($keyId)) {
            throw new JwtException('Key id must be a string');
        }

        $keys = $this->keys->keysFor($keyId, $algorithm);
        if ($keys === []) {
            throw new JwtException($keyId === null ? sprintf('No key verifies %s', $algorithm) : sprintf('Unknown key id "%s"', $keyId));
        }
        $verified = false;
        foreach ($keys as $key) {
            if ($key->verify($parsed['input'], $parsed['signature'], $algorithm)) {
                $verified = true;
                break;
            }
        }
        if (!$verified) {
            throw new JwtException('Invalid signature');
        }

        $this->validate($parsed['claims']);

        return $parsed['claims'];
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function validate(array $claims): void
    {
        $now = $this->clock?->now()->getTimestamp() ?? time();
        $leeway = $this->config->leeway;

        $exp = self::time($claims, 'exp');
        if ($exp === null && $this->config->requireExpiration) {
            throw new JwtException('Token has no expiration');
        }
        if ($exp !== null && $now - $leeway >= $exp) {
            throw new JwtException('Token expired');
        }
        $nbf = self::time($claims, 'nbf');
        if ($nbf !== null && $now + $leeway < $nbf) {
            throw new JwtException('Token not valid yet');
        }
        $iat = self::time($claims, 'iat');
        if ($iat !== null && $now + $leeway < $iat) {
            throw new JwtException('Token issued in the future');
        }

        if ($this->config->issuers !== [] && !in_array($claims['iss'] ?? null, $this->config->issuers, true)) {
            throw new JwtException('Unexpected issuer');
        }
        if ($this->config->audiences !== []) {
            $audience = $claims['aud'] ?? [];
            $audience = is_string($audience) ? [$audience] : (is_array($audience) ? $audience : []);
            if (array_intersect($this->config->audiences, array_filter($audience, is_string(...))) === []) {
                throw new JwtException('Token is not meant for this audience');
            }
        }
        foreach ($this->config->requiredClaims as $claim) {
            if (!array_key_exists($claim, $claims)) {
                throw new JwtException(sprintf('Token lacks the "%s" claim', $claim));
            }
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function time(array $claims, string $name): ?float
    {
        if (!array_key_exists($name, $claims)) {
            return null;
        }
        $value = $claims[$name];
        if (!is_int($value) && !is_float($value)) {
            throw new JwtException(sprintf('Claim "%s" must be a NumericDate', $name));
        }

        return (float) $value;
    }
}
