<?php

declare(strict_types=1);

namespace Keel\Tests\Http;

use Keel\Config\HttpConfig;
use Keel\Container\Definitions;
use Keel\Http\Attribute\BodyLimit;
use Keel\Http\CallableHandler;
use Keel\Http\Exception\ContentTooLargeException;
use Keel\Http\Middleware\BodyLimitConfig;
use Keel\Http\Middleware\BodyLimitMiddleware;
use Keel\Http\Stream\IteratorStream;
use Keel\Http\Stream\LimitedStream;
use Keel\Kernel\Kernel;
use Keel\Testing\TestClient;
use Keel\Tests\Http\Fixtures\LimitedController;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(BodyLimitMiddleware::class)]
#[CoversClass(BodyLimitConfig::class)]
#[CoversClass(BodyLimit::class)]
#[CoversClass(LimitedStream::class)]
final class BodyLimitTest extends TestCase
{
    private static function client(BodyLimitConfig $config): TestClient
    {
        $definitions = new Definitions();
        $definitions->instance(BodyLimitConfig::class, $config);

        return TestClient::forKernel(Kernel::build($definitions, [LimitedController::class], [BodyLimitMiddleware::class]));
    }

    public function testSizes(): void
    {
        self::assertSame(2 * 1024 * 1024, new BodyLimitConfig()->maxBytes);
        self::assertSame(512 * 1024, BodyLimitConfig::parseSize('512K'));
        self::assertSame(10 * 1024 * 1024, BodyLimitConfig::parseSize('10mb'));
        self::assertSame(1024 ** 3, BodyLimitConfig::parseSize(' 1G '));
        self::assertSame(100, BodyLimitConfig::parseSize(100));
        self::assertSame(0, new BodyLimitConfig(0)->maxBytes);

        foreach (['ten', '1T', '-5'] as $bad) {
            try {
                BodyLimitConfig::parseSize($bad);
                self::fail($bad . ' was accepted');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        BodyLimitConfig::parseSize(-1);
    }

    public function testMediaTypePatterns(): void
    {
        $config = new BodyLimitConfig(contentTypes: ['application/json', 'image/*', 'application/*+json', 'MULTIPART/form-data']);
        self::assertTrue($config->accepts('application/json'));
        self::assertTrue($config->accepts('image/png'));
        self::assertTrue($config->accepts('application/problem+json'));
        self::assertTrue($config->accepts('multipart/form-data'));
        self::assertFalse($config->accepts('application/xml'));
        self::assertFalse($config->accepts('text/json'));
        self::assertFalse($config->accepts(''));
        self::assertTrue(new BodyLimitConfig()->accepts('anything/at-all'));

        $this->expectException(\InvalidArgumentException::class);
        new BodyLimitConfig(contentTypes: ['json']);
    }

    public function testGlobalLimitAndRouteOverrides(): void
    {
        $client = self::client(new BodyLimitConfig(16));

        $client->post('/default', 'short', ['Content-Type' => 'text/plain'])->assertOk()->assertJson(['bytes' => 5]);
        $client->post('/default', str_repeat('x', 17), ['Content-Type' => 'text/plain'])->assertProblem(413, 'Request body exceeds 16 bytes');
        $client->get('/default')->assertStatus(405);

        // The route raises the limit to 1K: the global middleware reads it before routing.
        $client->post('/upload', str_repeat('x', 1000), ['Content-Type' => 'text/plain'])->assertOk()->assertJson(['bytes' => 1000]);
        $client->post('/upload', str_repeat('x', 1025), ['Content-Type' => 'text/plain'])->assertProblem(413, 'Request body exceeds 1024 bytes');
        $client->post('/upload', '{}', ['Content-Type' => 'application/vnd.api+json'])->assertOk();
        $client->post('/upload', '<x/>', ['Content-Type' => 'application/xml; charset=utf-8'])
            ->assertProblem(415, 'Content-Type "application/xml" is not accepted; send text/plain, application/*+json');
        $client->post('/upload', 'x')->assertProblem(415, 'Content-Type (none) is not accepted; send text/plain, application/*+json');

        // Only content types are set on /json: the global size limit stays.
        $client->json('POST', '/json', ['a' => 1])->assertOk()->assertJson(['fields' => 1]);
        $client->json('POST', '/json', ['a' => str_repeat('x', 20)])->assertStatus(413);
        $client->post('/json', 'a=1')->assertStatus(415);
    }

    public function testAttributeWorksWithoutGlobalMiddleware(): void
    {
        $client = TestClient::forKernel(Kernel::build(new Definitions(), [LimitedController::class]));

        $client->post('/upload', str_repeat('x', 1025), ['Content-Type' => 'text/plain'])->assertStatus(413);
        $client->post('/default', str_repeat('x', 5000), ['Content-Type' => 'text/plain'])->assertOk()->assertJson(['bytes' => 5000]);
        self::assertInstanceOf(HttpConfig::class, new HttpConfig([BodyLimitMiddleware::class]));
    }

    public function testChunkedBodyIsCutOffWhileReading(): void
    {
        $middleware = new BodyLimitMiddleware(new BodyLimitConfig(10));
        $seen = null;
        $handler = new CallableHandler(static function (ServerRequestInterface $request) use (&$seen): ResponseInterface {
            $seen = $request->getBody();
            (string) $request->getBody();

            return new Psr17Factory()->createResponse(200);
        });

        // A chunked upload: no Content-Length and a stream of unknown size.
        $request = new ServerRequest('POST', '/', ['Transfer-Encoding' => 'chunked'], new IteratorStream(['yyyyyy', 'yyyyy']));
        try {
            $middleware->process($request, $handler);
            self::fail('the oversized chunked body was read');
        } catch (ContentTooLargeException $e) {
            self::assertSame(413, $e->status);
        }
        self::assertInstanceOf(LimitedStream::class, $seen);

        $ok = new ServerRequest('POST', '/', ['Transfer-Encoding' => 'chunked'], new IteratorStream(['yyyyy', 'yyyyy']));
        self::assertSame(200, $middleware->process($ok, $handler)->getStatusCode());

        $declared = new ServerRequest('POST', '/', ['Content-Length' => '11'], new IteratorStream(['x']));
        $this->expectException(ContentTooLargeException::class);
        $middleware->process($declared, $handler);
    }

    public function testLimitedStreamDelegatesAndCountsByPosition(): void
    {
        $stream = new LimitedStream(Stream::create('0123456789'), 10);
        self::assertSame('0123', $stream->read(4));
        self::assertSame(4, $stream->tell());
        self::assertSame('456789', $stream->getContents());
        self::assertTrue($stream->eof());
        $stream->rewind();
        self::assertSame('0123456789', (string) $stream, 'reading twice is fine; the limit is on size, not on reads');
        self::assertSame(10, $stream->getSize());
        self::assertTrue($stream->isSeekable());
        self::assertTrue($stream->isReadable());
        self::assertTrue($stream->isWritable());
        $stream->seek(8);
        self::assertSame('89', $stream->read(100));
        self::assertIsArray($stream->getMetadata());
        self::assertSame(Stream::class, $stream->inner()::class);

        $small = new LimitedStream(Stream::create('abcdef'), 3);
        $this->expectException(ContentTooLargeException::class);
        $small->getContents();
    }
}
