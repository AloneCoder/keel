<?php

declare(strict_types=1);

namespace Keel\Auth;

use Keel\Routing\Router;
use Keel\Session\CookieSigner;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\UriInterface;

/**
 * Signed links (download, unsubscribe, email confirmation): a route path from the Router with an
 * optional expiry and an HMAC signature in the query string, checked on the way in by #[Signed].
 *
 * The signature (CookieSigner with SignedUrlConfig::$purpose) covers the path and the whole query
 * string exactly as sent, expiry included, so no parameter can be added, removed, reordered or
 * changed. Scheme and host are not covered: the link is valid wherever the application receives
 * the same path. A link stays valid until it expires or the secret changes; to revoke single
 * links, put something revocable in them (a token, a version) and check it in the handler.
 */
final class UrlSigner
{
    /** Query parameter with the signature. */
    public const string SIGNATURE = 'signature';

    /** Query parameter with the expiry, unix time. */
    public const string EXPIRES = 'expires';

    private readonly CookieSigner $signer;

    public function __construct(
        private readonly SignedUrlConfig $config,
        private readonly ?Router $router = null,
        private readonly ?ClockInterface $clock = null,
    ) {
        $this->signer = new CookieSigner($config->secret, $config->purpose);
    }

    /**
     * A signed link to a named route; parameters that are not placeholders become the query string.
     *
     * @param array<string, scalar|\Stringable|null> $params
     * @param int|\DateTimeInterface|null            $expires seconds from now or a moment; null = SignedUrlConfig::$ttl
     */
    public function route(string $name, array $params = [], int|\DateTimeInterface|null $expires = null): string
    {
        $router = $this->router ?? throw new \LogicException('UrlSigner::route() needs the Router; none is registered');

        return $this->sign($router->generate($name, $params), $expires);
    }

    /**
     * Signs a path as the application receives it, with an optional query ("/files/5?inline=1").
     *
     * @param int|\DateTimeInterface|null $expires seconds from now or a moment; null = SignedUrlConfig::$ttl
     */
    public function sign(string $path, int|\DateTimeInterface|null $expires = null): string
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '#')) {
            throw new \InvalidArgumentException(sprintf('UrlSigner signs a path with an optional query, such as "/files/5?inline=1"; got "%s"', $path));
        }
        [$path, $query] = explode('?', $path, 2) + [1 => ''];
        foreach ($query === '' ? [] : explode('&', $query) as $pair) {
            $name = explode('=', $pair, 2)[0];
            if ($name === self::SIGNATURE || $name === self::EXPIRES) {
                throw new \InvalidArgumentException(sprintf('The query parameter "%s" is reserved for the URL signature', $name));
            }
        }

        $expiresAt = $this->expiresAt($expires ?? $this->config->ttl);
        if ($expiresAt !== null) {
            $query .= ($query === '' ? '' : '&') . self::EXPIRES . '=' . $expiresAt;
        }
        $signature = $this->signer->signature($path . '?' . $query);
        $query .= ($query === '' ? '' : '&') . self::SIGNATURE . '=' . $signature;

        return rtrim($this->config->baseUrl ?? '', '/') . $path . '?' . $query;
    }

    /**
     * Whether the link carries a genuine, unexpired signature: a request URI, a path with its query
     * or a full URL (SignedUrlConfig::$baseUrl in front of it is ignored).
     */
    public function verify(UriInterface|string $url): bool
    {
        return $this->status($url) === SignedUrlStatus::Valid;
    }

    public function status(UriInterface|string $url): SignedUrlStatus
    {
        [$path, $query] = $this->target($url);
        $signature = null;
        $expires = null;
        $signed = [];
        foreach ($query === '' ? [] : explode('&', $query) as $pair) {
            [$name, $value] = explode('=', $pair, 2) + [1 => ''];
            if ($name === self::SIGNATURE) {
                if ($signature !== null) {
                    return SignedUrlStatus::Invalid;
                }
                $signature = rawurldecode($value);
                continue;
            }
            if ($name === self::EXPIRES) {
                if ($expires !== null) {
                    return SignedUrlStatus::Invalid;
                }
                $expires = $value;
            }
            $signed[] = $pair;
        }

        if ($signature === null) {
            return SignedUrlStatus::Missing;
        }
        if (!hash_equals($this->signer->signature($path . '?' . implode('&', $signed)), $signature)) {
            return SignedUrlStatus::Invalid;
        }
        if ($expires !== null && (!ctype_digit($expires) || (int) $expires <= $this->now())) {
            return SignedUrlStatus::Expired;
        }

        return SignedUrlStatus::Valid;
    }

    private function expiresAt(int|\DateTimeInterface|null $expires): ?int
    {
        if ($expires instanceof \DateTimeInterface) {
            return $expires->getTimestamp();
        }
        if ($expires === null) {
            return null;
        }
        if ($expires < 1) {
            throw new \InvalidArgumentException(sprintf('A signed URL must stay valid at least 1 second, got %d', $expires));
        }

        return $this->now() + $expires;
    }

    /**
     * @return array{0: string, 1: string} path, query
     */
    private function target(UriInterface|string $url): array
    {
        if ($url instanceof UriInterface) {
            return [$url->getPath() === '' ? '/' : $url->getPath(), $url->getQuery()];
        }

        $base = rtrim($this->config->baseUrl ?? '', '/');
        if ($base !== '' && str_starts_with($url, $base . '/')) {
            $url = substr($url, strlen($base));
        }
        if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
            $parts = parse_url($url);

            return [is_array($parts) && ($parts['path'] ?? '') !== '' ? $parts['path'] : '/', is_array($parts) ? $parts['query'] ?? '' : ''];
        }
        [$path, $query] = explode('?', explode('#', $url, 2)[0], 2) + [1 => ''];

        return [$path, $query];
    }

    private function now(): int
    {
        return ($this->clock?->now() ?? new \DateTimeImmutable())->getTimestamp();
    }
}
