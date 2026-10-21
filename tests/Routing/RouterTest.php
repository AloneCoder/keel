<?php

declare(strict_types=1);

namespace Keel\Tests\Routing;

use Keel\Routing\CompiledRoutes;
use Keel\Routing\Exception\RoutingException;
use Keel\Routing\Matcher;
use Keel\Routing\MatchStatus;
use Keel\Routing\Route;
use Keel\Routing\RouteCollector;
use Keel\Routing\RouteCompiler;
use Keel\Routing\Router;
use Keel\Routing\UrlGenerator;
use Keel\Tests\Kernel\Fixtures\AuthMiddleware;
use Keel\Tests\Kernel\Fixtures\BrokenController;
use Keel\Tests\Kernel\Fixtures\ClassMiddleware;
use Keel\Tests\Kernel\Fixtures\MethodMiddleware;
use Keel\Tests\Kernel\Fixtures\UserController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
#[CoversClass(RouteCollector::class)]
#[CoversClass(RouteCompiler::class)]
#[CoversClass(CompiledRoutes::class)]
#[CoversClass(Matcher::class)]
#[CoversClass(UrlGenerator::class)]
#[CoversClass(Route::class)]
final class RouterTest extends TestCase
{
    public function testCollectsAttributesWithPrefixMiddlewareAndStatus(): void
    {
        $routes = new RouteCollector()->addController(UserController::class)->routes();
        $byName = [];
        foreach ($routes as $route) {
            $byName[$route->handler[1] . ':' . $route->path] = $route;
        }

        $show = $byName['show:/api/users/{id:\d+}'];
        self::assertSame(['GET'], $show->methods);
        self::assertSame('users.show', $show->name);
        self::assertSame([ClassMiddleware::class], $show->middleware);
        self::assertNull($show->status);

        $create = $byName['create:/api/users'];
        self::assertSame(['POST'], $create->methods);
        self::assertSame(201, $create->status);

        $secret = $byName['secret:/api/users/secret'];
        self::assertSame([ClassMiddleware::class, AuthMiddleware::class, MethodMiddleware::class], $secret->middleware);

        self::assertSame(['GET', 'POST'], $byName['multi:/api/users/multi']->methods);
        self::assertArrayHasKey('multi:/api/users/multi-alias', $byName, 'repeated attributes produce several routes');
    }

    public function testStaticHandlersAreRejected(): void
    {
        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('public instance method');
        new RouteCollector()->addController(BrokenController::class);
    }

    public function testDuplicateNamesAreRejected(): void
    {
        $collector = new RouteCollector();
        $collector->add(new Route('/a', ['GET'], [UserController::class, 'html'], 'same'));

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('"same" is declared twice');
        $collector->add(new Route('/b', ['GET'], [UserController::class, 'html'], 'same'));
    }

    public function testMatchesStaticBeforeDynamicAndDecodesParams(): void
    {
        $router = Router::fromControllers(UserController::class);

        $search = $router->match('GET', '/api/users/search');
        self::assertSame(MatchStatus::Found, $search->status);
        self::assertSame('users.search', $search->match?->route->name);

        $show = $router->match('GET', '/api/users/42');
        self::assertSame(['id' => '42'], $show->match?->params);

        $post = $router->match('GET', '/api/users/7/posts/hello%20world');
        self::assertSame(['id' => '7', 'slug' => 'hello world'], $post->match?->params);
    }

    public function testConstraintsAreCheckedOnTheRawPath(): void
    {
        $router = Router::fromControllers(UserController::class);

        self::assertSame(MatchStatus::NotFound, $router->match('GET', '/api/users/42abc')->status);
        self::assertSame(MatchStatus::NotFound, $router->match('GET', '/api/users/%34%32')->status, 'encoded digits do not satisfy \d+');
        self::assertSame(MatchStatus::NotFound, $router->match('GET', '/api/users/42/')->status, 'trailing slash is a different path');
    }

    public function testMethodNotAllowedListsAllowedMethods(): void
    {
        $router = Router::fromControllers(UserController::class);

        $result = $router->match('PATCH', '/api/users/42');

        self::assertSame(MatchStatus::MethodNotAllowed, $result->status);
        self::assertSame(['GET', 'PUT', 'DELETE'], $result->allowed);
    }

    public function testHeadFallsBackToGet(): void
    {
        $router = Router::fromControllers(UserController::class);

        self::assertSame(MatchStatus::Found, $router->match('HEAD', '/api/users/42')->status);
        self::assertSame(MatchStatus::Found, $router->match('head', '/api/users/search')->status);
    }

    public function testChunkedRegexesMatchEveryRoute(): void
    {
        $routes = [];
        for ($i = 0; $i < 25; $i++) {
            $vars = '';
            for ($v = 0; $v < $i % 4; $v++) {
                $vars .= '/{v' . $i . '_' . $v . '}';
            }
            $routes[] = new Route('/r' . $i . $vars, ['GET'], [UserController::class, 'html'], 'r' . $i);
        }
        $router = Router::fromRoutes($routes);

        for ($i = 0; $i < 25; $i++) {
            $path = '/r' . $i;
            $expected = [];
            for ($v = 0; $v < $i % 4; $v++) {
                $path .= '/x' . $v;
                $expected['v' . $i . '_' . $v] = 'x' . $v;
            }
            $result = $router->match('GET', $path);
            self::assertNotNull($result->match, $path);
            self::assertSame('r' . $i, $result->match->route->name, $path);
            self::assertSame($expected, $result->match->params, $path);
        }
    }

    public function testCacheRoundTrip(): void
    {
        $path = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/routes.php';
        Router::fromControllers(UserController::class)->write($path);

        $router = Router::fromCache($path);

        $result = $router->match('GET', '/api/users/5/posts/abc');
        self::assertNotNull($result->match);
        self::assertSame('users.post', $result->match->route->name);
        self::assertSame([UserController::class, 'post'], $result->match->route->handler);
        self::assertSame('/api/users/5', $router->generate('users.show', ['id' => 5]));
        self::assertCount(count(Router::fromControllers(UserController::class)->routes()), $router->routes());
    }

    public function testMissingCacheIsReported(): void
    {
        $this->expectException(RoutingException::class);
        Router::fromCache('/nonexistent/routes.php');
    }

    public function testGeneratesUrlsWithQueryString(): void
    {
        $router = Router::fromControllers(UserController::class);

        self::assertSame('/api/users/5', $router->generate('users.show', ['id' => 5]));
        self::assertSame('/api/users/5?page=2&sort=name', $router->generate('users.show', ['id' => 5, 'page' => 2, 'sort' => 'name', 'skip' => null]));
        self::assertSame('/api/users/5/posts/hello%20w%C3%B6rld', $router->generate('users.post', ['id' => 5, 'slug' => 'hello wörld']));
        self::assertSame('/api/users/search', $router->generate('users.search'));
    }

    public function testGenerateValidatesParameters(): void
    {
        $router = Router::fromControllers(UserController::class);

        try {
            $router->generate('users.show');
            self::fail('missing parameter must throw');
        } catch (RoutingException $e) {
            self::assertStringContainsString('needs parameter "id"', $e->getMessage());
        }

        try {
            $router->generate('users.show', ['id' => 'abc']);
            self::fail('pattern violation must throw');
        } catch (RoutingException $e) {
            self::assertStringContainsString('do not satisfy', $e->getMessage());
        }

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('not defined');
        $router->generate('nope');
    }

    public function testStaticRouteDeclaredTwiceIsRejected(): void
    {
        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('declared twice');
        Router::fromRoutes([
            new Route('/a', ['GET'], [UserController::class, 'html']),
            new Route('/a', ['GET', 'POST'], [UserController::class, 'raw']),
        ]);
    }
}
