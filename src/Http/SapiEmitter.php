<?php

declare(strict_types=1);

namespace Keel\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Sends a PSR-7 response through the SAPI (php-fpm, cli-server, FrankenPHP).
 *
 * A non-seekable body (Responder::stream(), Server-Sent Events) is streamed: every chunk is
 * flushed through PHP's output buffers to the client as it is read, and once the client is gone
 * (connection_aborted()) the body is closed, which ends its generator.
 */
final class SapiEmitter implements EmitterInterface
{
    public function __construct(private readonly int $chunkSize = 8192) {}

    /**
     * Ends the response for the client while the script keeps running (fastcgi_finish_request()
     * under php-fpm, frankenphp_finish_request() under FrankenPHP, litespeed_finish_request()):
     * what runs afterwards (deferred tasks, ResponseSent listeners) no longer delays the client.
     * Returns false when the SAPI cannot do it (CLI, cli-server).
     */
    public static function finish(): bool
    {
        foreach (['frankenphp_finish_request', 'fastcgi_finish_request', 'litespeed_finish_request'] as $function) {
            if (function_exists($function)) {
                return (bool) $function();
            }
        }

        return false;
    }

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
            while (!$body->eof()) {
                echo $body->read($this->chunkSize);
            }

            return;
        }

        try {
            while (!$body->eof()) {
                echo $body->read($this->chunkSize);
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                if (connection_aborted() === 1) {
                    break;
                }
            }
        } finally {
            $body->close();
        }
    }
}
