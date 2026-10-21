<?php

declare(strict_types=1);

namespace Keel\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Sends a PSR-7 response through the SAPI (php-fpm, cli-server, FrankenPHP).
 */
final class SapiEmitter implements EmitterInterface
{
    public function __construct(private readonly int $chunkSize = 8192) {}

    public function emit(ResponseInterface $response): void
    {
        if (headers_sent($file, $line)) {
            throw new \RuntimeException(sprintf('Headers already sent in %s:%d', $file, $line));
        }

        $status = $response->getStatusCode();
        header(sprintf(
            'HTTP/%s %d %s',
            $response->getProtocolVersion(),
            $status,
            $response->getReasonPhrase(),
        ), true, $status);

        foreach ($response->getHeaders() as $name => $values) {
            $replace = strtolower($name) !== 'set-cookie';
            foreach ($values as $value) {
                header($name . ': ' . $value, $replace);
                $replace = false;
            }
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            echo $body->read($this->chunkSize);
        }
    }
}
