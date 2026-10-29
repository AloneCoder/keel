<?php

declare(strict_types=1);

namespace Keel\Runtime;

use Nyholm\Psr7Server\ServerRequestCreator;
use Nyholm\Psr7Server\ServerRequestCreatorInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The default request creator: nyholm's, except that the URI scheme comes from the server
 * (REQUEST_SCHEME, HTTPS) and never from X-Forwarded-Proto, which nyholm takes from any client.
 * The header stays on the request; TrustedProxiesMiddleware applies it when a trusted proxy sent it.
 */
final class GlobalsRequestCreator implements ServerRequestCreatorInterface
{
    private const string PROTO = 'HTTP_X_FORWARDED_PROTO';

    public function __construct(private readonly ServerRequestCreator $creator) {}

    public function fromGlobals(): ServerRequestInterface
    {
        if (!array_key_exists(self::PROTO, $_SERVER)) {
            return $this->creator->fromGlobals();
        }

        $proto = $_SERVER[self::PROTO];
        unset($_SERVER[self::PROTO]);
        try {
            return $this->creator->fromGlobals();
        } finally {
            $_SERVER[self::PROTO] = $proto;
        }
    }

    /**
     * @param array<mixed>      $server
     * @param array<mixed>      $headers
     * @param array<mixed>      $cookie
     * @param array<mixed>      $get
     * @param array<mixed>|null $post
     * @param array<mixed>      $files
     */
    public function fromArrays(
        array $server,
        array $headers = [],
        array $cookie = [],
        array $get = [],
        ?array $post = null,
        array $files = [],
        $body = null,
    ): ServerRequestInterface {
        unset($server[self::PROTO]);

        return $this->creator->fromArrays($server, $headers, $cookie, $get, $post, $files, $body);
    }

    /**
     * @param array<mixed> $server
     *
     * @return array<mixed>
     */
    public static function getHeadersFromServer(array $server): array
    {
        return ServerRequestCreator::getHeadersFromServer($server);
    }
}
