<?php

declare(strict_types=1);

namespace Keel\Tests\Idempotency;

use Keel\Auth\Authentication;
use Keel\Auth\Identity;
use Keel\Cache\ArrayCache;
use Keel\Container\Definitions;
use Keel\Http\CallableHandler;
use Keel\Http\Exception\ConflictException;
use Keel\Idempotency\Attribute\Idempotent;
use Keel\Idempotency\IdempotencyConfig;
use Keel\Idempotency\IdempotencyMiddleware;
use Keel\Kernel\Kernel;
use Keel\RateLimit\InMemoryCounters;
use Keel\Testing\TestClient;
use Keel\Tests\Idempotency\Fixtures\OrderController;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\SimpleCache\CacheInterface;

#[CoversClass(IdempotencyMiddleware::class)]
#[CoversClass(IdempotencyConfig::class)]
#[CoversClass(Idempotent::class)]
final class IdempotencyTest extends TestCase
{
    private ArrayCache $cache;

    protected function setUp(): void
    {
        OrderController::$created = 0;
        OrderController::$fail = false;
        $this->cache = new ArrayCache();
    }

    /**
     * @param list<class-string<MiddlewareInterface>> $middleware
     */
    private function client(array $middleware = []): TestClient
    {
        $definitions = new Definitions();
        $definitions->instance(CacheInterface::class, $this->cache);
        $definitions->set(InMemoryCounters::class)->alias(\Keel\RateLimit\CounterStorage::class);

        return TestClient::forKernel(Kernel::build($definitions, [OrderController::class], $middleware));
    }

    public function testRepeatGetsTheStoredResponse(): void
    {
        $client = $this->client();

        $first = $client->json('POST', '/orders', ['item' => 'book'], ['Idempotency-Key' => 'k-1'])->assertStatus(201)->assertJson(['id' => 1, 'item' => 'book']);
        $first->assertHeaderMissing(IdempotencyMiddleware::REPLAYED);

        $again = $client->json('POST', '/orders', ['item' => 'book'], ['Idempotency-Key' => 'k-1'])->assertStatus(201)->assertJson(['id' => 1]);
        $again->assertHeader(IdempotencyMiddleware::REPLAYED, 'true')->assertHeader('Content-Type', 'application/json');
        self::assertSame(1, OrderController::$created, 'the handler ran once');

        $client->json('POST', '/orders', ['item' => 'book'], ['Idempotency-Key' => '"k-2"'])->assertStatus(201)->assertJson(['id' => 2]);
        $client->json('POST', '/orders', ['item' => 'book'], ['Idempotency-Key' => 'k-2'])->assertJson(['id' => 2])->assertHeader(IdempotencyMiddleware::REPLAYED, 'true');
    }

    public function testMisuseIsRefused(): void
    {
        $client = $this->client();

        $client->json('POST', '/orders', ['item' => 'book'])->assertProblem(400, 'This request needs an Idempotency-Key header');
        $client->json('POST', '/orders', ['item' => 'book'], ['Idempotency-Key' => str_repeat('k', 256)])->assertStatus(400);
        $client->json('POST', '/orders', ['item' => 'book'], ['Idempotency-Key' => 'ключ'])->assertStatus(400); // visible ASCII only

        $client->json('POST', '/orders', ['item' => 'book'], ['Idempotency-Key' => 'k-3'])->assertStatus(201);
        $client->json('POST', '/orders', ['item' => 'pen'], ['Idempotency-Key' => 'k-3'])->assertProblem(422, 'This Idempotency-Key was already used for a different request');
        $client->json('POST', '/orders?x=1', ['item' => 'book'], ['Idempotency-Key' => 'k-3'])->assertStatus(422); // the query is part of the request
    }

    public function testFailureReleasesTheKey(): void
    {
        $client = $this->client();
        OrderController::$fail = true;
        $client->json('POST', '/orders', ['item' => 'book'], ['Idempotency-Key' => 'k-4'])->assertStatus(500);

        OrderController::$fail = false;
        $client->json('POST', '/orders', ['item' => 'book'], ['Idempotency-Key' => 'k-4'])->assertStatus(201)->assertJson(['id' => 1])->assertHeaderMissing(IdempotencyMiddleware::REPLAYED);
        $client->json('POST', '/orders', ['item' => 'book'], ['Idempotency-Key' => 'k-4'])->assertHeader(IdempotencyMiddleware::REPLAYED, 'true');
    }

    public function testConcurrentRepeatGets409(): void
    {
        $locks = new InMemoryCounters();
        $middleware = new IdempotencyMiddleware($this->cache, null, $locks);
        $inner = null;
        $handler = new CallableHandler(function (ServerRequestInterface $request) use (&$inner, $middleware): ResponseInterface {
            // While the first request runs, the same key arrives again.
            try {
                $middleware->process(new ServerRequest('POST', '/pay', ['Idempotency-Key' => 'k-5'], 'body'), new CallableHandler(static fn(): ResponseInterface => throw new \LogicException('must not run')));
            } catch (ConflictException $e) {
                $inner = $e;
            }

            return new Psr17Factory()->createResponse(200);
        });

        $middleware->process(new ServerRequest('POST', '/pay', ['Idempotency-Key' => 'k-5'], 'body'), $handler);

        self::assertInstanceOf(ConflictException::class, $inner);
        self::assertSame(['Retry-After' => '1'], $inner->headers);
    }

    public function testGlobalMiddlewareScopesAndRouteSettings(): void
    {
        $client = $this->client([IdempotencyMiddleware::class]);

        // Global: POST and PATCH with a key are idempotent; without a key they just run.
        $client->post('/payments', null, ['Idempotency-Key' => 'p-1'])->assertJson(['payment' => 1]);
        $client->post('/payments', null, ['Idempotency-Key' => 'p-1'])->assertJson(['payment' => 1])->assertHeader(IdempotencyMiddleware::REPLAYED, 'true');
        $client->post('/payments')->assertJson(['payment' => 2]);
        $client->post('/payments')->assertJson(['payment' => 3]);
        $client->patch('/notes', null, ['Idempotency-Key' => 'n-1'])->assertJson(['note' => 4]);
        $client->patch('/notes', null, ['Idempotency-Key' => 'n-1'])->assertJson(['note' => 4]);

        // The route's settings apply through the global middleware too, PUT included.
        $client->json('POST', '/orders', ['item' => 'x'])->assertStatus(400);
        $client->put('/carts/9', null, ['Idempotency-Key' => 'c-1'])->assertJson(['cart' => 9, 'n' => 5]);
        $client->put('/carts/9', null, ['Idempotency-Key' => 'c-1'])->assertJson(['n' => 5])->assertHeader(IdempotencyMiddleware::REPLAYED, 'true');

        // Another client (other credentials) with the same key does not get this response.
        $client->withHeader('Authorization', 'Bearer other')->post('/payments', null, ['Idempotency-Key' => 'p-1'])->assertJson(['payment' => 6])->assertHeaderMissing(IdempotencyMiddleware::REPLAYED);
    }

    public function testPrincipalScopeAndUnstorableResponses(): void
    {
        $middleware = new IdempotencyMiddleware($this->cache, new IdempotencyConfig(methods: ['post']), new InMemoryCounters());
        $calls = 0;
        $handler = static function (int $status) use (&$calls): CallableHandler {
            return new CallableHandler(static function () use (&$calls, $status): ResponseInterface {
                $calls++;

                return new Psr17Factory()->createResponse($status);
            });
        };
        $as = static fn(string $id): ServerRequestInterface => Authentication::attach(new ServerRequest('POST', '/x', ['Idempotency-Key' => 'same']), new Identity($id));

        $middleware->process($as('ann'), $handler(200));
        $middleware->process($as('bob'), $handler(200));
        $middleware->process($as('ann'), $handler(200));
        self::assertSame(2, $calls, 'keys are per principal');

        $middleware->process(new ServerRequest('POST', '/y', ['Idempotency-Key' => 'busy']), $handler(429));
        $middleware->process(new ServerRequest('POST', '/y', ['Idempotency-Key' => 'busy']), $handler(503));
        $middleware->process(new ServerRequest('POST', '/y', ['Idempotency-Key' => 'busy']), $handler(200));
        self::assertSame(5, $calls, '429 and 5xx are not stored; the key was released each time');

        self::assertSame(['POST'], new IdempotencyConfig(methods: ['post'])->methods);
        $this->expectException(\InvalidArgumentException::class);
        new IdempotencyConfig(prefix: 'idem:');
    }

    public function testAttributeValue(): void
    {
        self::assertSame(['required' => true, 'ttl' => 60], new Idempotent(true, 60)->value());
        self::assertSame([IdempotencyMiddleware::class], new Idempotent()->middleware());
        self::assertSame(Idempotent::KEY, new Idempotent()->key());
        self::assertSame(30, new IdempotencyConfig()->merge(['ttl' => 30])->ttl);
        self::assertTrue(new IdempotencyConfig()->merge(['required' => true])->required);

        $this->expectException(\InvalidArgumentException::class);
        new Idempotent(ttl: 0);
    }
}
