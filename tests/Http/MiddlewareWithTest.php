<?php

declare(strict_types=1);

namespace Keel\Tests\Http;

use Keel\Container\Definitions;
use Keel\Http\Pipeline;
use Keel\Kernel\Kernel;
use Keel\Routing\Attribute\MiddlewareWith;
use Keel\Routing\RouteCollector;
use Keel\Routing\Router;
use Keel\Testing\TestClient;
use Keel\Tests\Http\Fixtures\ConfiguredController;
use Keel\Tests\Http\Fixtures\HeaderMiddleware;
use Keel\Tests\Kernel\Fixtures\RequestCounter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiddlewareWith::class)]
#[CoversClass(Pipeline::class)]
#[CoversClass(RouteCollector::class)]
final class MiddlewareWithTest extends TestCase
{
    public function testArgumentsTravelWithTheRouteAndThroughTheCache(): void
    {
        $routes = new RouteCollector()->addController(ConfiguredController::class)->routes();
        self::assertSame([
            [HeaderMiddleware::class, ['header' => 'X-Class', 'times' => 2]],
            [HeaderMiddleware::class, ['header' => 'X-Method', 'suffix' => '!']],
        ], $routes[0]->middleware);
        self::assertCount(1, $routes[1]->middleware, 'the same class with the same arguments is listed once');

        $file = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/with-' . uniqid() . '.php';
        Router::fromRoutes($routes)->write($file);
        self::assertSame($routes[0]->middleware, Router::fromCache($file)->routes()[0]->middleware);
        unlink($file);

        $this->expectException(\InvalidArgumentException::class);
        new MiddlewareWith(HeaderMiddleware::class, 'positional');
    }

    public function testMiddlewareIsBuiltFromArgumentsAndTheContainer(): void
    {
        $definitions = new Definitions();
        $definitions->set(RequestCounter::class);
        $kernel = Kernel::build($definitions, [ConfiguredController::class]);
        $client = TestClient::forKernel($kernel);

        $client->get('/a')->assertOk()->assertHeader('X-Class', 'xx')->assertHeader('X-Method', 'x!')->assertHeader('X-Hits', '2');
        $client->get('/b')->assertOk()->assertHeader('X-Class', 'xx')->assertHeaderMissing('X-Method')->assertHeader('X-Hits', '1');

        $client->get('/bad')->assertStatus(500);
    }

    public function testGlobalMiddlewareAcceptsArgumentPairs(): void
    {
        $definitions = new Definitions();
        $definitions->set(RequestCounter::class);
        $client = TestClient::forKernel(Kernel::build($definitions, [ConfiguredController::class], [[HeaderMiddleware::class, ['header' => 'X-Global', 'times' => 3]]]));

        $client->get('/b')->assertOk()->assertHeader('X-Global', 'xxx');
    }
}
