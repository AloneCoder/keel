<?php

declare(strict_types=1);

namespace Keel\Tests\Http;

use Keel\Container\Definitions;
use Keel\Http\Attribute\CacheControl;
use Keel\Http\CallableHandler;
use Keel\Http\Middleware\HttpCacheConfig;
use Keel\Http\Middleware\HttpCacheMiddleware;
use Keel\Kernel\Kernel;
use Keel\Testing\TestClient;
use Keel\Tests\Http\Fixtures\CachedController;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(HttpCacheMiddleware::class)]
#[CoversClass(HttpCacheConfig::class)]
#[CoversClass(CacheControl::class)]
final class HttpCacheTest extends TestCase
{
    private static function handler(ResponseInterface $response): CallableHandler
    {
        return new CallableHandler(static fn(ServerRequestInterface $request): ResponseInterface => $response);
    }

    private static function response(string $body, int $status = 200): ResponseInterface
    {
        $factory = new Psr17Factory();

        return $factory->createResponse($status)->withHeader('Content-Type', 'text/plain')->withBody($factory->createStream($body));
    }

    public function testDirectives(): void
    {
        self::assertNull(new HttpCacheConfig()->directive());
        self::assertSame('public', new HttpCacheConfig(public: true)->directive());
        self::assertSame('private, max-age=60', new HttpCacheConfig(maxAge: 60)->directive());
        self::assertSame('public, max-age=3600, s-maxage=7200, must-revalidate, immutable', new HttpCacheConfig(maxAge: 3600, sharedMaxAge: 7200, public: true, mustRevalidate: true, immutable: true)->directive());
        self::assertSame('no-store', new HttpCacheConfig(maxAge: 60, noStore: true)->directive());
        self::assertSame(new HttpCacheConfig(maxAge: 5)->toArray(), HttpCacheConfig::fromArray(new CacheControl(maxAge: 5)->value())->toArray());
        self::assertSame([HttpCacheMiddleware::class], new CacheControl()->middleware());
        self::assertSame(CacheControl::KEY, new CacheControl()->key());
        self::assertTrue(HttpCacheConfig::fromArray(['etag' => 'yes', 'maxAge' => '5'])->etag, 'mistyped values fall back to defaults');

        $this->expectException(\InvalidArgumentException::class);
        new HttpCacheConfig(maxAge: -1);
    }

    public function testEtagIsComputedAndIfNoneMatchAnswered304(): void
    {
        $middleware = new HttpCacheMiddleware(new Psr17Factory());

        $first = $middleware->process(new ServerRequest('GET', '/'), self::handler(self::response('hello')));
        $etag = $first->getHeaderLine('ETag');
        self::assertMatchesRegularExpression('/^"[a-f0-9]{32}"$/', $etag);
        self::assertSame('hello', (string) $first->getBody(), 'the body is rewound after hashing');
        self::assertFalse($first->hasHeader('Cache-Control'), 'no directive unless configured');

        $cached = $middleware->process(new ServerRequest('GET', '/', ['If-None-Match' => $etag]), self::handler(self::response('hello')));
        self::assertSame(304, $cached->getStatusCode());
        self::assertSame('', (string) $cached->getBody());
        self::assertSame($etag, $cached->getHeaderLine('ETag'));
        self::assertFalse($cached->hasHeader('Content-Type'));

        self::assertSame(304, $middleware->process(new ServerRequest('GET', '/', ['If-None-Match' => '"other", W/' . $etag]), self::handler(self::response('hello')))->getStatusCode(), 'weak comparison over a list');
        self::assertSame(304, $middleware->process(new ServerRequest('GET', '/', ['If-None-Match' => '*']), self::handler(self::response('hello')))->getStatusCode());
        self::assertSame(200, $middleware->process(new ServerRequest('GET', '/', ['If-None-Match' => $etag]), self::handler(self::response('changed')))->getStatusCode());
        self::assertSame(200, $middleware->process(new ServerRequest('POST', '/', ['If-None-Match' => $etag]), self::handler(self::response('hello')))->getStatusCode(), 'only GET and HEAD');
        self::assertSame(404, $middleware->process(new ServerRequest('GET', '/', ['If-None-Match' => '*']), self::handler(self::response('nope', 404)))->getStatusCode(), 'only 200');
    }

    public function testIfModifiedSinceAgainstLastModified(): void
    {
        $middleware = new HttpCacheMiddleware(new Psr17Factory(), new HttpCacheConfig(etag: false, maxAge: 30, public: true));
        $response = self::response('doc')->withHeader('Last-Modified', 'Wed, 21 Oct 2015 07:28:00 GMT');

        $fresh = $middleware->process(new ServerRequest('GET', '/', ['If-Modified-Since' => 'Wed, 21 Oct 2015 07:28:00 GMT']), self::handler($response));
        self::assertSame(304, $fresh->getStatusCode());
        self::assertSame('public, max-age=30', $fresh->getHeaderLine('Cache-Control'));
        self::assertFalse($fresh->hasHeader('ETag'));

        self::assertSame(200, $middleware->process(new ServerRequest('GET', '/', ['If-Modified-Since' => 'Tue, 20 Oct 2015 07:28:00 GMT']), self::handler($response))->getStatusCode());
        self::assertSame(200, $middleware->process(new ServerRequest('GET', '/', ['If-Modified-Since' => 'garbage']), self::handler($response))->getStatusCode());
        self::assertSame(200, $middleware->process(new ServerRequest('GET', '/', ['If-Modified-Since' => 'Wed, 21 Oct 2015 07:28:00 GMT']), self::handler(self::response('no validator')))->getStatusCode());
    }

    public function testAttributeSettingsApplyPerRoute(): void
    {
        $client = TestClient::forKernel(Kernel::build(new Definitions(), [CachedController::class]));

        $public = $client->get('/public')->assertOk()->assertHeader('Cache-Control', 'public, max-age=3600');
        $etag = $public->header('ETag');
        self::assertNotSame('', $etag);
        $client->get('/public', ['If-None-Match' => $etag])->assertStatus(304)->assertHeader('Cache-Control', 'public, max-age=3600');
        $client->post('/public', [], ['If-None-Match' => $etag])->assertOk();

        $client->get('/private')->assertOk()->assertHeader('Cache-Control', 'private, max-age=60, must-revalidate')->assertHeaderMissing('ETag');
        $client->get('/secret')->assertOk()->assertHeader('Cache-Control', 'no-store')->assertHeaderMissing('ETag');
        $client->get('/plain')->assertOk()->assertHeaderMissing('Cache-Control')->assertHeaderMissing('ETag');
        $client->get('/own')->assertOk()->assertHeader('Cache-Control', 'no-cache')->assertHeader('ETag', '"handler"');
        $client->get('/own', ['If-None-Match' => '"handler"'])->assertStatus(304)->assertHeader('Last-Modified', 'Wed, 21 Oct 2015 07:28:00 GMT');
    }
}
