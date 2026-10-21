<?php

declare(strict_types=1);

namespace Keel\Tests\Routing;

use Keel\Routing\Attribute\Middleware;
use Keel\Routing\Route;
use Keel\Routing\RouteCollector;
use Keel\Routing\Router;
use Keel\Tests\Routing\Fixtures\Flag;
use Keel\Tests\Routing\Fixtures\FlagController;
use Keel\Tests\Routing\Fixtures\NoopMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouteCollector::class)]
#[CoversClass(Route::class)]
#[CoversClass(Middleware::class)]
final class RouteOptionsTest extends TestCase
{
    public function testOptionsAndProvidedMiddlewareAreCollectedAndCached(): void
    {
        $routes = new RouteCollector()->addController(FlagController::class)->routes();
        self::assertCount(2, $routes);

        [$inherited, $overridden] = $routes;
        self::assertSame(['flag' => 'class'], $inherited->options);
        self::assertSame('class', $inherited->option('flag'));
        self::assertNull($inherited->option('missing'));
        self::assertSame([NoopMiddleware::class], $inherited->middleware, 'provided middleware is added once even when declared twice');

        self::assertSame(['flag' => 'method'], $overridden->options);
        self::assertSame([NoopMiddleware::class], $overridden->middleware);

        $file = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/options-' . uniqid() . '.php';
        Router::fromRoutes($routes)->write($file);
        $cached = Router::fromCache($file)->routes();
        self::assertSame(['flag' => 'method'], $cached[1]->options);
        unlink($file);
    }

    public function testRoutesFromOlderCachesWithoutOptionsStillLoad(): void
    {
        $route = Route::fromArray(['path' => '/', 'methods' => ['GET'], 'handler' => [FlagController::class, 'a'], 'name' => null, 'middleware' => [], 'status' => null]);
        self::assertSame([], $route->options);
        self::assertSame([], Route::fromArray($route->toArray())->options);
    }

    public function testFlagAttributeIsBothOptionAndMiddleware(): void
    {
        $flag = new Flag('x');
        self::assertSame('flag', $flag->key());
        self::assertSame('x', $flag->value());
        self::assertSame([NoopMiddleware::class], $flag->middleware());
    }
}
