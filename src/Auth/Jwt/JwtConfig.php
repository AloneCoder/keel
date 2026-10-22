<?php

declare(strict_types=1);

namespace Keel\Auth\Jwt;

/**
 * Settings of JwtAuthenticator. Register one in config/jwt.php:
 *
 *   // tokens of an identity provider
 *   new JwtConfig(algorithms: ['RS256'], jwksUrl: 'https://login.example.com/.well-known/jwks.json',
 *       issuer: 'https://login.example.com/', audience: 'orders-api');
 *
 *   // tokens you issue yourself with Jwt::encode()
 *   new JwtConfig(algorithms: ['HS256'], secret: $env->get('JWT_SECRET'), issuer: 'orders-api');
 */
final readonly class JwtConfig
{
    /** @var list<string> */
    public array $algorithms;

    /** @var list<string> */
    public array $issuers;

    /** @var list<string> */
    public array $audiences;

    /**
     * @param list<string>              $algorithms        accepted "alg" values: HS256/384/512, RS256/384/512, ES256/384/512, EdDSA
     * @param string|null               $secret            HMAC secret for HS*, at least 32 bytes
     * @param array<array-key, string>  $publicKeys        PEM public keys (RSA, EC, Ed25519), keyed by key id or as a list
     * @param string|null               $jwksUrl           JWKS endpoint of the issuer (https); needs a PSR-18 client (HttpClientProvider)
     * @param string|list<string>|null  $issuer            required "iss", or any of several
     * @param string|list<string>|null  $audience          required "aud": the token must name at least one
     * @param int                       $leeway            seconds of clock skew tolerated on exp, nbf and iat
     * @param bool                      $requireExpiration refuse tokens without "exp"
     * @param list<string>              $requiredClaims    claims that must be present
     * @param string                    $subjectClaim      claim holding the principal id
     * @param string|null               $rolesClaim        dotted path to the roles ("roles", "realm_access.roles"): a list or a space-separated string
     * @param int                       $jwksTtl           seconds JWKS keys are kept before they are fetched again
     * @param string                    $realm             realm of the WWW-Authenticate challenge
     */
    public function __construct(
        array $algorithms = ['RS256'],
        #[\SensitiveParameter]
        public ?string $secret = null,
        public array $publicKeys = [],
        public ?string $jwksUrl = null,
        string|array|null $issuer = null,
        string|array|null $audience = null,
        public int $leeway = 60,
        public bool $requireExpiration = true,
        public array $requiredClaims = [],
        public string $subjectClaim = 'sub',
        public ?string $rolesClaim = 'roles',
        public int $jwksTtl = 3600,
        public string $realm = 'api',
    ) {
        if ($algorithms === []) {
            throw new \InvalidArgumentException('JwtConfig needs at least one algorithm');
        }
        foreach ($algorithms as $algorithm) {
            if (!isset(Jwt::ALGORITHMS[$algorithm])) {
                throw new \InvalidArgumentException(sprintf('Unsupported JWT algorithm "%s" (supported: %s)', $algorithm, implode(', ', array_keys(Jwt::ALGORITHMS))));
            }
        }
        if ($secret === null && $publicKeys === [] && $jwksUrl === null) {
            throw new \InvalidArgumentException('JwtConfig needs a secret, public keys or a JWKS URL');
        }
        if ($jwksUrl !== null && !self::secureUrl($jwksUrl)) {
            throw new \InvalidArgumentException(sprintf('JWKS URL "%s" must use https (http only for localhost)', $jwksUrl));
        }
        if ($leeway < 0 || $jwksTtl < 1) {
            throw new \InvalidArgumentException('JWT leeway must not be negative and the JWKS TTL must be positive');
        }
        $this->algorithms = $algorithms;
        $this->issuers = $issuer === null ? [] : (array) $issuer;
        $this->audiences = $audience === null ? [] : (array) $audience;
    }

    /**
     * The secret and public keys of this config as a key set; null when it only has a JWKS URL.
     */
    public function staticKeys(): ?StaticKeySet
    {
        $keys = [];
        if ($this->secret !== null) {
            $keys[] = JwtKey::secret($this->secret);
        }
        foreach ($this->publicKeys as $id => $pem) {
            $keys[] = JwtKey::publicKey($pem, is_string($id) ? $id : null);
        }

        return $keys === [] ? null : new StaticKeySet(...$keys);
    }

    private static function secureUrl(string $url): bool
    {
        $scheme = parse_url($url, \PHP_URL_SCHEME);
        $host = parse_url($url, \PHP_URL_HOST);

        return $scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true));
    }
}
