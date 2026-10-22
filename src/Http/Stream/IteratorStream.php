<?php

declare(strict_types=1);

namespace Keel\Http\Stream;

use Psr\Http\Message\StreamInterface;

/**
 * A read-only, non-seekable body produced chunk by chunk from an iterable, typically a generator:
 * nothing runs until the emitter reads, every read() returns at most the next chunk, so each
 * chunk reaches the client as soon as it is produced (Server-Sent Events, NDJSON, large exports).
 * close() drops the iterator, which runs the generator's finally blocks: emitters close the body
 * when the client disconnects.
 *
 * Empty chunks are skipped. Exceptions thrown by the generator surface from read() or eof(),
 * after the status and headers were sent; catch what you can inside the generator.
 */
final class IteratorStream implements StreamInterface
{
    /** @var \Iterator<mixed, mixed>|null */
    private ?\Iterator $iterator = null;

    /** @var iterable<mixed, string|\Stringable>|\Closure(): iterable<mixed, string|\Stringable>|null */
    private iterable|\Closure|null $source;

    private string $buffer = '';

    private int $position = 0;

    private bool $done = false;

    /** The current element was consumed; the next fill must advance first. */
    private bool $advance = false;

    /**
     * @param iterable<mixed, string|\Stringable>|\Closure(): iterable<mixed, string|\Stringable> $chunks a closure is called on the first read
     */
    public function __construct(iterable|\Closure $chunks)
    {
        $this->source = $chunks;
    }

    public function read(int $length): string
    {
        if ($length < 1) {
            return '';
        }
        if ($this->buffer === '') {
            $this->fill();
        }
        $chunk = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, strlen($chunk));
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(\PHP_INT_MAX);
        }

        return $contents;
    }

    /**
     * The rest of the stream: a non-seekable body cannot be read from the start again.
     */
    public function __toString(): string
    {
        return $this->getContents();
    }

    /**
     * True once the iterator is exhausted. May run the generator up to its next chunk.
     */
    public function eof(): bool
    {
        if ($this->buffer !== '') {
            return false;
        }
        if (!$this->done) {
            $this->fill();
        }

        return $this->buffer === '';
    }

    public function close(): void
    {
        $this->iterator = null;
        $this->source = null;
        $this->buffer = '';
        $this->done = true;
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    /**
     * Only a no-op seek to the start of an unread stream is accepted (RoadRunner rewinds before
     * streaming); anything else throws, the stream cannot go back.
     */
    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        if ($offset === 0 && $whence === \SEEK_SET && $this->position === 0) {
            return;
        }

        throw new \RuntimeException('IteratorStream is not seekable');
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('IteratorStream is not writable');
    }

    public function isReadable(): bool
    {
        return !$this->done || $this->buffer !== '';
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }

    private function fill(): void
    {
        $iterator = $this->iterator();
        if ($iterator === null) {
            return;
        }
        while (true) {
            if ($this->advance) {
                $iterator->next();
            }
            $this->advance = true;
            if (!$iterator->valid()) {
                $this->close();

                return;
            }
            $value = $iterator->current();
            if (!is_string($value) && !$value instanceof \Stringable) {
                throw new \UnexpectedValueException(sprintf('A streamed body yields strings, got %s', get_debug_type($value)));
            }
            $chunk = (string) $value;
            if ($chunk !== '') {
                $this->buffer = $chunk;

                return;
            }
        }
    }

    /**
     * @return \Iterator<mixed, mixed>|null
     */
    private function iterator(): ?\Iterator
    {
        if ($this->iterator !== null || $this->source === null) {
            return $this->iterator;
        }
        $source = $this->source instanceof \Closure ? ($this->source)() : $this->source;
        $this->source = null;
        // Anything but a generator is iterated through one, which rewinds it the way foreach would.
        $this->iterator = $source instanceof \Generator ? $source : (static function () use ($source): \Generator {
            yield from $source;
        })();

        return $this->iterator;
    }
}
