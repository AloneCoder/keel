<?php

declare(strict_types=1);

namespace Keel\Auth;

use Keel\Http\Exception\UnauthorizedException;
use Keel\RateLimit\CounterStorage;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Request signing for machine clients:
 *
 *   Authorization: HMAC <keyId>:<unix timestamp>:<hex HMAC-SHA256>
 *   signature = HMAC-SHA256(secret, "METHOD\n/path?query\n<timestamp>\n" . sha256(body))
 *
 * The timestamp must be within $tolerance seconds of the server clock, which bounds replays;
 * with a CounterStorage ($replays) every signature is accepted once, so an identical request
 * replayed inside the tolerance window is rejected too. Pair it with TLS. Clients build the
 * header with HmacAuthenticator::sign().
 *
 * Host and Content-Type are not part of the signed string: a signature is valid on every host
 * sharing the secret, so do not share secrets between environments.
 */
final class HmacAuthenticator implements Authenticator
{
    public const string SCHEME = 'HMAC';

    private const string KEY_ID_PATTERN = '/^[^:\s]{1,128}$/';

    public function __construct(
        private readonly HmacSecrets $secrets,
        private readonly int $tolerance = 300,
        private readonly ?ClockInterface $clock = null,
        private readonly ?CounterStorage $replays = null,
    ) {}

    /**
     * @throws \InvalidArgumentException when $keyId cannot travel in the header (colon, whitespace, empty, over 128 bytes)
     */
    public static function sign(string $keyId, string $secret, string $method, string $target, string $body, int $timestamp): string
    {
        if (preg_match(self::KEY_ID_PATTERN, $keyId) !== 1) {
            throw new \InvalidArgumentException('HMAC key id must be 1-128 characters without colons or whitespace');
        }

        return sprintf('%s %s:%d:%s', self::SCHEME, $keyId, $timestamp, self::signature($secret, $method, $target, $body, $timestamp));
    }

    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with(strtoupper($header), self::SCHEME . ' ')) {
            return null;
        }

        if (preg_match('/^\S+\s+([^:\s]{1,128}):(\d{1,12}):([0-9a-f]{64})\s*$/i', $header, $matches) !== 1) {
            throw $this->unauthorized('Malformed HMAC authorization header');
        }
        [, $keyId, $timestamp, $signature] = $matches;
        $timestamp = (int) $timestamp;
        $signature = strtolower($signature);

        $now = $this->clock?->now()->getTimestamp() ?? time();
        if (abs($now - $timestamp) > $this->tolerance) {
            throw $this->unauthorized('Signature timestamp out of range');
        }

        $uri = $request->getUri();
        $target = $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');

        // An unknown key id gets the same answer, after the same work, as a wrong signature.
        $secret = $this->secrets->secretFor($keyId);
        $expected = self::signature($secret ?? 'keel-unknown-key-id', $request->getMethod(), $target, (string) $request->getBody(), $timestamp);
        if ($secret === null || !hash_equals($expected, $signature)) {
            throw $this->unauthorized('Invalid signature');
        }

        if ($this->replays !== null && $this->replays->increment('hmac:' . $keyId . ':' . $signature, 2 * $this->tolerance + 1) > 1) {
            throw $this->unauthorized('Replayed signature');
        }

        return new Identity($keyId, [], ['scheme' => 'hmac']);
    }

    public function challenge(): string
    {
        return self::SCHEME;
    }

    public function securityScheme(): SecurityScheme
    {
        return new SecurityScheme('hmac', [
            'type' => 'http',
            'scheme' => 'hmac',
            'description' => 'Authorization: HMAC <keyId>:<timestamp>:<hex HMAC-SHA256 of "METHOD\\n/path?query\\n<timestamp>\\n" + sha256(body)>',
        ]);
    }

    private static function signature(string $secret, string $method, string $target, string $body, int $timestamp): string
    {
        $canonical = strtoupper($method) . "\n" . $target . "\n" . $timestamp . "\n" . hash('sha256', $body);

        return hash_hmac('sha256', $canonical, $secret);
    }

    private function unauthorized(string $message): UnauthorizedException
    {
        return new UnauthorizedException($message, ['WWW-Authenticate' => $this->challenge()]);
    }
}
