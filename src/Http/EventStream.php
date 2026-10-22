<?php

declare(strict_types=1);

namespace Keel\Http;

/**
 * A handler return value the Responder sends as Server-Sent Events: a text/event-stream response
 * whose body is produced from $events while it is sent.
 *
 *   #[Get('/orders/{id}/progress')]
 *   public function progress(int $id): EventStream
 *   {
 *       return new EventStream(function () use ($id) {
 *           foreach ($this->tracker->follow($id) as $step) {
 *               yield new SseEvent($step, event: 'progress', id: (string) $step->sequence);
 *           }
 *       });
 *   }
 *
 * Items may be SseEvent objects, strings (sent as data) or any JSON-encodable value. Every open
 * stream occupies a worker until the generator returns or the client disconnects.
 */
final readonly class EventStream
{
    /**
     * @param iterable<mixed, mixed>|\Closure(): iterable<mixed, mixed> $events
     * @param array<string, string>                                     $headers extra response headers
     */
    public function __construct(
        public iterable|\Closure $events,
        public array $headers = [],
    ) {}
}
