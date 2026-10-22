<?php

declare(strict_types=1);

namespace Keel\Http\Stream;

use Keel\Http\Exception\ContentTooLargeException;
use Psr\Http\Message\StreamInterface;

/**
 * A request body that refuses to be read past $limit bytes: the read that crosses the limit
 * throws ContentTooLargeException (413), so a chunked upload without Content-Length cannot fill
 * the memory of a worker. Seeking moves the count with the position: reading the same body twice
 * is fine, a body larger than the limit is not. Unlike PSR-7's advice, __toString() throws too,
 * since returning a truncated body would be worse.
 *
 * @internal created by BodyLimitMiddleware
 */
final class LimitedStream implements StreamInterface
{
    private const int CHUNK = 65_536;

    private int $position = 0;

    public function __construct(private readonly StreamInterface $stream, private readonly int $limit) {}

    public function inner(): StreamInterface
    {
        return $this->stream;
    }

    public function read(int $length): string
    {
        $chunk = $this->stream->read($length);
        $this->position += strlen($chunk);
        if ($this->position > $this->limit) {
            throw new ContentTooLargeException(sprintf('Request body exceeds %d bytes', $this->limit));
        }

        return $chunk;
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->stream->eof()) {
            $chunk = $this->read(self::CHUNK);
            if ($chunk === '') {
                break;
            }
            $contents .= $chunk;
        }

        return $contents;
    }

    public function __toString(): string
    {
        if ($this->isSeekable()) {
            $this->rewind();
        }

        return $this->getContents();
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        $this->stream->seek($offset, $whence);
        $this->position = $this->stream->tell();
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function tell(): int
    {
        return $this->stream->tell();
    }

    public function eof(): bool
    {
        return $this->stream->eof();
    }

    public function isSeekable(): bool
    {
        return $this->stream->isSeekable();
    }

    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    public function isWritable(): bool
    {
        return $this->stream->isWritable();
    }

    public function write(string $string): int
    {
        return $this->stream->write($string);
    }

    public function getSize(): ?int
    {
        return $this->stream->getSize();
    }

    public function close(): void
    {
        $this->stream->close();
    }

    public function detach()
    {
        return $this->stream->detach();
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $this->stream->getMetadata($key);
    }
}
