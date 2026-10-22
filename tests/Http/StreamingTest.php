<?php

declare(strict_types=1);

namespace Keel\Tests\Http;

use Keel\Container\Definitions;
use Keel\Http\EventStream;
use Keel\Http\Responder;
use Keel\Http\SapiEmitter;
use Keel\Http\SseEvent;
use Keel\Http\Stream\IteratorStream;
use Keel\Kernel\Kernel;
use Keel\Testing\TestClient;
use Keel\Tests\Http\Fixtures\ModernController;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[CoversClass(IteratorStream::class)]
#[CoversClass(SseEvent::class)]
#[CoversClass(EventStream::class)]
#[CoversClass(Responder::class)]
#[CoversClass(SapiEmitter::class)]
final class StreamingTest extends TestCase
{
    public function testIteratorStreamIsLazyAndReadsChunkByChunk(): void
    {
        $log = [];
        $stream = new IteratorStream(static function () use (&$log): \Generator {
            $log[] = 'start';
            yield 'first';
            $log[] = 'after first';
            yield '';
            yield 'second-chunk';
            $log[] = 'end';
        });
        self::assertSame([], $log, 'nothing runs before the first read');
        self::assertFalse($stream->isSeekable());
        self::assertFalse($stream->isWritable());
        self::assertNull($stream->getSize());
        $stream->rewind(); // allowed before anything was read

        self::assertSame('fir', $stream->read(3));
        self::assertSame(['start'], $log, 'the generator is not advanced past the chunk being read');
        self::assertSame('st', $stream->read(100), 'a read returns the rest of the current chunk only');
        self::assertSame(['start'], $log);
        self::assertSame('second-chunk', $stream->read(100), 'empty chunks are skipped');
        self::assertSame(5 + 12, $stream->tell());
        self::assertTrue($stream->eof());
        self::assertSame(['start', 'after first', 'end'], $log);
        self::assertSame('', $stream->read(10));
        self::assertFalse($stream->isReadable());

        $this->expectException(\RuntimeException::class);
        $stream->rewind();
    }

    public function testIteratorStreamSourcesAndClose(): void
    {
        self::assertSame('abc', (string) new IteratorStream(['a', 'b', 'c']));
        self::assertSame('xy', new IteratorStream(new \ArrayObject(['x', 'y']))->getContents());
        self::assertSame('', new IteratorStream([])->getContents());

        $state = new \ArrayObject(['closed' => false]);
        $generator = (static function () use ($state): \Generator {
            try {
                yield 'one';
                yield 'two';
            } finally {
                $state['closed'] = true;
            }
        })();
        $stream = new IteratorStream($generator);
        unset($generator);
        self::assertSame('one', $stream->read(10));
        $stream->close();
        self::assertTrue($state['closed'], 'closing drops the generator, which runs its finally block');
        self::assertTrue($stream->eof());
        self::assertNull($stream->detach());
        self::assertSame([], $stream->getMetadata());
        self::assertNull($stream->getMetadata('uri'));
    }

    public function testIteratorStreamRefusesWrites(): void
    {
        $this->expectException(\RuntimeException::class);
        new IteratorStream(['a'])->write('b');
    }

    public function testSseEventFormat(): void
    {
        self::assertSame("data: hello\n\n", new SseEvent('hello')->format());
        self::assertSame("id: 7\nevent: update\nretry: 1500\ndata: {\"a\":1}\n\n", new SseEvent(['a' => 1], 'update', '7', 1500)->format());
        self::assertSame("data: line 1\ndata: line 2\ndata: line 3\n\n", new SseEvent("line 1\r\nline 2\rline 3")->format());
        self::assertSame(": ping\n\n", SseEvent::comment('ping')->format());
        self::assertSame(":\n\n", SseEvent::comment()->format());
        self::assertSame("retry: 10\n\n", new SseEvent(retry: 10)->format());

        foreach ([static fn() => new SseEvent(event: "a\nb"), static fn() => new SseEvent(id: "1\0"), static fn() => new SseEvent(retry: -1)] as $case) {
            try {
                $case();
                self::fail('accepted');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEventStreamAndChunkedResponsesThroughTheKernel(): void
    {
        $client = TestClient::forKernel(Kernel::build(new Definitions(), [ModernController::class]));

        $events = $client->get('/events')->assertOk()
            ->assertHeader('Content-Type', 'text/event-stream')
            ->assertHeader('Cache-Control', 'no-cache')
            ->assertHeader('X-Accel-Buffering', 'no')
            ->assertHeader('X-Stream', 'yes');
        self::assertSame("id: 1\nevent: tick\ndata: {\"n\":1}\n\ndata: plain text\n\n: ping\n\n", $events->body());

        $ndjson = $client->get('/ndjson')->assertOk()->assertHeader('Content-Type', 'application/x-ndjson');
        self::assertSame("{\"id\":1}\n{\"id\":2}\n", $ndjson->body());

        $factory = new Psr17Factory();
        $responder = new Responder($factory, $factory);
        $response = $responder->respond(new EventStream([new SseEvent('x')]), 201);
        self::assertSame(201, $response->getStatusCode());
        self::assertInstanceOf(IteratorStream::class, $response->getBody());
        self::assertSame('no-store', $responder->eventStream([], headers: ['Cache-Control' => 'no-store'])->getHeaderLine('Cache-Control'));
    }

    /**
     * Streamed bodies are flushed chunk by chunk and closed afterwards.
     */
    #[RunInSeparateProcess]
    public function testSapiEmitterStreamsNonSeekableBodies(): void
    {
        $closed = false;
        $body = new IteratorStream(static function () use (&$closed): \Generator {
            try {
                yield "data: 1\n\n";
                yield "data: 2\n\n";
            } finally {
                $closed = true;
            }
        });
        $response = new Psr17Factory()->createResponse(200)->withBody($body);

        ob_start();
        ob_start();
        try {
            new SapiEmitter()->emit($response);
        } finally {
            ob_end_flush();
            $output = ob_get_clean();
        }

        self::assertSame("data: 1\n\ndata: 2\n\n", $output);
        self::assertTrue($closed);
        self::assertFalse(SapiEmitter::finish(), 'the CLI cannot finish a request early');
    }
}
