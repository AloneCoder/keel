<?php

declare(strict_types=1);

namespace Keel\Auth\Jwt;

use Keel\Auth\Authenticator;
use Keel\Auth\Identity;
use Keel\Auth\Principal;
use Keel\Auth\SecurityScheme;
use Keel\Http\Exception\UnauthorizedException;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * "Authorization: Bearer <JWT>" verified with JwtConfig: a shared secret, PEM public keys or
 * the JWKS endpoint of an identity provider. List it in AuthConfig::$authenticators.
 *
 * The principal is an Identity: the id from the subject claim, the roles from JwtConfig::$rolesClaim
 * (what #[Auth('admin')] checks), and every claim as an attribute, plus "scopes" as a list read from
 * "scope" (space-separated) or "scp". A bearer token that fails verification is answered 401 with
 * error="invalid_token"; why it failed is logged at info level, never told to the client.
 */
final class JwtAuthenticator implements Authenticator
{
    private ?JwtVerifier $verifier = null;

    public function __construct(
        private readonly JwtConfig $config,
        private readonly ?ClientInterface $http = null,
        private readonly ?RequestFactoryInterface $requests = null,
        private readonly ?CacheInterface $cache = null,
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?KeySet $keys = null,
    ) {}

    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        $header = $request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer(?:\s|$)/i', $header) !== 1) {
            return null;
        }
        if (preg_match('/^Bearer\s+(\S+)\s*$/i', $header, $matches) !== 1) {
            throw new UnauthorizedException('Malformed bearer token', ['WWW-Authenticate' => $this->challenge() . ', error="invalid_request"']);
        }

        try {
            return $this->principal($this->verifier()->verify($matches[1]));
        } catch (JwtException $exception) {
            $this->logger?->info('JWT rejected: ' . $exception->getMessage());

            throw new UnauthorizedException('Invalid or expired token', ['WWW-Authenticate' => $this->challenge() . ', error="invalid_token"'], previous: $exception);
        }
    }

    public function challenge(): string
    {
        return sprintf('Bearer realm="%s"', $this->config->realm);
    }

    public function securityScheme(): SecurityScheme
    {
        return SecurityScheme::bearer('bearer', 'JWT');
    }

    /**
     * The verifier this authenticator uses, for code that checks tokens outside a request
     * (WebSocket handshakes, CLI tools).
     */
    public function verifier(): JwtVerifier
    {
        return $this->verifier ??= new JwtVerifier($this->config, $this->keys ?? $this->keySet(), $this->clock);
    }

    private function keySet(): KeySet
    {
        $sets = [];
        $static = $this->config->staticKeys();
        if ($static !== null) {
            $sets[] = $static;
        }
        if ($this->config->jwksUrl !== null) {
            if ($this->http === null || $this->requests === null) {
                throw new \LogicException('JwtConfig::$jwksUrl needs a PSR-18 ClientInterface and a RequestFactoryInterface: load HttpClientProvider');
            }
            $sets[] = new JwksKeySet($this->config->jwksUrl, $this->http, $this->requests, $this->cache, $this->config->jwksTtl, clock: $this->clock, logger: $this->logger);
        }

        return count($sets) === 1 ? $sets[0] : new ChainKeySet(...$sets);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function principal(array $claims): Identity
    {
        $subject = $claims[$this->config->subjectClaim] ?? null;
        if (!is_string($subject) && !is_int($subject)) {
            throw new JwtException(sprintf('Token has no "%s" claim', $this->config->subjectClaim));
        }

        $scopes = $claims['scope'] ?? $claims['scp'] ?? [];

        return new Identity(
            (string) $subject,
            $this->config->rolesClaim === null ? [] : self::strings(self::path($claims, $this->config->rolesClaim)),
            $claims + ['scopes' => self::strings($scopes)],
        );
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function path(array $claims, string $path): mixed
    {
        $value = $claims;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (is_string($value)) {
            return array_values(array_filter(preg_split('/[\s,]+/', $value) ?: [], static fn(string $s): bool => $s !== ''));
        }

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
