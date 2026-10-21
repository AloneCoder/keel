<?php

declare(strict_types=1);

namespace Keel\Auth;

use Keel\Http\Exception\UnauthorizedException;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Request signing for machine clients:
 *
 *   Authorization: HMAC <keyId>:<unix timestamp>:<hex HMAC-SHA256>
 *   signature = HMAC-SHA256(secret, "METHOD\n/path?query\n<timestamp>\n" . sha256(body))
 *
 * The timestamp must be within $tolerance seconds of the server clock, which bounds replays;
 * pair it with TLS. Clients build the header with HmacAuthenticator::sign().
 */
final class HmacAuthenticator implements Authenticator
{
    public const string SCHEME = 'HMAC';

    public function __construct(
        private readonly HmacSecrets $secrets,
        private readonly int $tolerance = 300,
        private readonly ?ClockInterface $clock = null,
    ) {}

    public static function sign(string $keyId, string $secret, string $method, string $target, string $body, int $timestamp): string
    {
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

        $now = $this->clock?->now()->getTimestamp() ?? time();
        if (abs($now - $timestamp) > $this->tolerance) {
            throw $this->unauthorized('Signature timestamp out of range');
        }

        $secret = $this->secrets->secretFor($keyId);
        if ($secret === null) {
            throw $this->unauthorized('Unknown key id');
        }

        $uri = $request->getUri();
        $target = $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');
        $expected = self::signature($secret, $request->getMethod(), $target, (string) $request->getBody(), $timestamp);
        if (!hash_equals($expected, strtolower($signature))) {
            throw $this->unauthorized('Invalid signature');
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
