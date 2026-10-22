<?php

declare(strict_types=1);

namespace Keel\Http;

/**
 * One Server-Sent Event. A string $data is sent as is (one "data:" line per line), anything else
 * as JSON; null sends no data line, for an event that only sets the id or the retry delay.
 */
final readonly class SseEvent
{
    /**
     * @param mixed       $data    string, or a value json_encode() accepts
     * @param string|null $event   event type for addEventListener(); default "message"
     * @param string|null $id      last event id, sent back by the browser as Last-Event-ID on reconnect
     * @param int|null    $retry   reconnection delay in milliseconds
     * @param string|null $comment a comment line, ignored by clients; keeps idle connections open through proxies
     */
    public function __construct(
        public mixed $data = null,
        public ?string $event = null,
        public ?string $id = null,
        public ?int $retry = null,
        public ?string $comment = null,
    ) {
        foreach (['event' => $event, 'id' => $id] as $field => $value) {
            if ($value !== null && preg_match('/[\r\n\0]/', $value) === 1) {
                throw new \InvalidArgumentException(sprintf('SSE %s must not contain line breaks or NUL', $field));
            }
        }
        if ($retry !== null && $retry < 0) {
            throw new \InvalidArgumentException('SSE retry must not be negative');
        }
    }

    /**
     * A comment-only event, the usual heartbeat: yield SseEvent::comment('ping').
     */
    public static function comment(string $text = ''): self
    {
        return new self(comment: $text);
    }

    /**
     * The event in the text/event-stream format, terminated by a blank line.
     */
    public function format(): string
    {
        $out = '';
        if ($this->comment !== null) {
            foreach (self::lines($this->comment) as $line) {
                $out .= ':' . ($line === '' ? '' : ' ' . $line) . "\n";
            }
        }
        if ($this->id !== null) {
            $out .= 'id: ' . $this->id . "\n";
        }
        if ($this->event !== null) {
            $out .= 'event: ' . $this->event . "\n";
        }
        if ($this->retry !== null) {
            $out .= 'retry: ' . $this->retry . "\n";
        }
        if ($this->data !== null) {
            $data = is_string($this->data) ? $this->data : json_encode($this->data, Responder::JSON_FLAGS);
            foreach (self::lines($data) as $line) {
                $out .= 'data: ' . $line . "\n";
            }
        }

        return $out . "\n";
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        return preg_split('/\r\n|\r|\n/', $text) ?: [''];
    }
}
