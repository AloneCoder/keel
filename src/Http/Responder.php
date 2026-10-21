<?php

declare(strict_types=1);

namespace Keel\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Turns a handler's return value into a PSR-7 response:
 * ResponseInterface as is; null => 204; string => text/html; anything else => JSON.
 */
final class Responder
{
    public const int JSON_FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public function respond(mixed $result, ?int $status = null): ResponseInterface
    {
        if ($result instanceof ResponseInterface) {
            return $status === null ? $result : $result->withStatus($status);
        }

        if ($result === null) {
            return $this->responses->createResponse($status ?? 204);
        }

        if (is_string($result)) {
            return $this->text($result, $status ?? 200, 'text/html; charset=utf-8');
        }

        return $this->json($result, $status ?? 200);
    }

    public function json(mixed $data, int $status = 200, string $contentType = 'application/json'): ResponseInterface
    {
        return $this->text(json_encode($data, self::JSON_FLAGS), $status, $contentType);
    }

    public function text(string $body, int $status = 200, string $contentType = 'text/plain; charset=utf-8'): ResponseInterface
    {
        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', $contentType)
            ->withBody($this->streams->createStream($body));
    }
}
