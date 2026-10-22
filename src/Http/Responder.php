<?php

declare(strict_types=1);

namespace Keel\Http;

use Keel\Http\Stream\IteratorStream;
use Keel\Routing\Router;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Turns a handler's return value into a PSR-7 response:
 * ResponseInterface as is; null => 204; string => text/html; Redirect => 3xx with Location;
 * EventStream => Server-Sent Events; anything else => JSON.
 */
final class Responder
{
    public const int JSON_FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @param Router|null $router resolves Redirect::route(); without it only Redirect::to() works
     */
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        private readonly ?Router $router = null,
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

        if ($result instanceof Redirect) {
            $location = $result->location ?? $this->generate($result);

            return $this->redirect($location, $status ?? $result->status);
        }

        if ($result instanceof EventStream) {
            return $this->eventStream($result->events, $status ?? 200, $result->headers);
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

    /**
     * A response whose body is produced while it is sent: each chunk of $chunks (strings, typically
     * yielded by a generator) is flushed to the client as soon as it exists. For NDJSON:
     * $responder->stream(function () use ($rows) { foreach ($rows as $row) { yield json_encode($row) . "\n"; } }, 'application/x-ndjson').
     *
     * @param iterable<mixed, string|\Stringable>|\Closure(): iterable<mixed, string|\Stringable> $chunks
     * @param array<string, string>                                                             $headers
     */
    public function stream(iterable|\Closure $chunks, string $contentType = 'application/octet-stream', int $status = 200, array $headers = []): ResponseInterface
    {
        $response = $this->responses->createResponse($status)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('X-Accel-Buffering', 'no')
            ->withBody(new IteratorStream($chunks));
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * Server-Sent Events: items are SseEvent objects, strings (sent as data) or JSON-encodable values.
     * Sent with Cache-Control: no-cache and X-Accel-Buffering: no, so proxies pass events through.
     *
     * @param iterable<mixed, mixed>|\Closure(): iterable<mixed, mixed> $events
     * @param array<string, string>                                     $headers
     */
    public function eventStream(iterable|\Closure $events, int $status = 200, array $headers = []): ResponseInterface
    {
        $chunks = static function () use ($events): \Generator {
            foreach ($events instanceof \Closure ? $events() : $events as $event) {
                yield ($event instanceof SseEvent ? $event : new SseEvent($event))->format();
            }
        };

        return $this->stream($chunks, 'text/event-stream', $status, $headers + ['Cache-Control' => 'no-cache']);
    }

    /**
     * An empty response with a Location header. A CR or LF in the location is refused by PSR-7;
     * validate user-supplied targets before redirecting to them.
     */
    public function redirect(string $location, int $status = 302): ResponseInterface
    {
        if ($status < 300 || $status > 399) {
            throw new \InvalidArgumentException(sprintf('A redirect needs a 3xx status, got %d', $status));
        }

        return $this->responses->createResponse($status)->withHeader('Location', $location);
    }

    private function generate(Redirect $redirect): string
    {
        if ($this->router === null) {
            throw new \LogicException(sprintf('Redirect::route("%s") needs a Router in the Responder; none is registered', (string) $redirect->routeName));
        }

        return $this->router->generate((string) $redirect->routeName, $redirect->params);
    }
}
