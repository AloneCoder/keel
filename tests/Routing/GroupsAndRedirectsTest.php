<?php

declare(strict_types=1);

namespace Keel\Tests\Routing;

use Keel\Container\Definitions;
use Keel\Http\Redirect;
use Keel\Http\Responder;
use Keel\Kernel\Kernel;
use Keel\OpenApi\Generator;
use Keel\Routing\Attribute\Redirect as RedirectRoute;
use Keel\Routing\Exception\RoutingException;
use Keel\Routing\Redirector;
use Keel\Routing\Route;
use Keel\Routing\RouteCollector;
use Keel\Routing\Router;
use Keel\Testing\TestClient;
use Keel\Tests\Kernel\Fixtures\ClassMiddleware;
use Keel\Tests\Routing\Fixtures\GroupedController;
use Keel\Tests\Routing\Fixtures\NoopMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouteCollector::class)]
#[CoversClass(RedirectRoute::class)]
#[CoversClass(Redirector::class)]
#[CoversClass(Redirect::class)]
#[CoversClass(Responder::class)]
final class GroupsAndRedirectsTest extends TestCase
{
    public function testParentClassesActAsRouteGroups(): void
    {
        $routes = new RouteCollector()->addController(GroupedController::class)->routes();
        $byName = [];
        foreach ($routes as $route) {
            $byName[$route->path] = $route;
        }

        self::assertSame(['/api/v1/users/legacy/{id}', '/api/v1/users/old', '/api/v1/users/{id:\d+}', '/api/v1/users/me'], array_keys($byName), 'the whole prefix chain applies, to redirects too');

        $show = $byName['/api/v1/users/{id:\d+}'];
        self::assertSame([NoopMiddleware::class, ClassMiddleware::class], $show->middleware, 'parent middleware runs first');
        self::assertSame('group', $show->option('flag'), 'parent options are inherited');
        self::assertSame('method', $byName['/api/v1/users/me']->option('flag'), 'and overridden below');
    }

    public function testRedirectAttributesBecomeRoutes(): void
    {
        $routes = new RouteCollector()->addController(GroupedController::class)->routes();
        $legacy = $routes[0];

        self::assertSame([Redirector::class, 'redirect'], $legacy->handler);
        self::assertSame('users.legacy', $legacy->name);
        self::assertSame(['GET'], $legacy->methods);
        self::assertSame(['to' => '/api/v1/users/{id}', 'status' => 308], $legacy->option(RedirectRoute::KEY));
        self::assertSame([NoopMiddleware::class, ClassMiddleware::class], $legacy->middleware, 'class middleware applies to redirects too');
        self::assertSame(['GET', 'POST'], $routes[1]->methods);
    }

    public function testRedirectRoutesAnswerWithLocation(): void
    {
        $client = TestClient::forKernel(Kernel::build(new Definitions(), [GroupedController::class]));

        $client->get('/api/v1/users/legacy/7')->assertStatus(308)->assertHeader('Location', '/api/v1/users/7');
        $client->get('/api/v1/users/legacy/a%2Fb')->assertStatus(308)->assertHeader('Location', '/api/v1/users/a%2Fb');
        $client->post('/api/v1/users/old')->assertStatus(302)->assertHeader('Location', 'https://example.test/new');
        $client->get('/api/v1/users/7')->assertOk()->assertJson(['id' => 7]);

        $document = new Generator(Router::fromControllers(GroupedController::class))->generate();
        self::assertSame(['/api/v1/users/{id}', '/api/v1/users/me'], array_keys((array) $document['paths']), 'redirects are left out of OpenAPI');
    }

    public function testRedirectAttributeValidatesStatusAndTarget(): void
    {
        self::assertSame(['to' => '/b', 'status' => 302], new RedirectRoute('/a', '/b')->value());

        try {
            new RedirectRoute('/a', '/b', 200);
            self::fail('status accepted');
        } catch (RoutingException $e) {
            self::assertStringContainsString('3xx redirect status', $e->getMessage());
        }

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('target path or absolute http(s) URL');
        new RedirectRoute('/a', 'javascript:alert(1)');
    }

    public function testResponderTurnsRedirectValuesIntoResponses(): void
    {
        $factory = new Psr17Factory();
        $router = Router::fromControllers(GroupedController::class);
        $responder = new Responder($factory, $factory, $router);

        $to = $responder->respond(Redirect::to('/login'));
        self::assertSame(302, $to->getStatusCode());
        self::assertSame('/login', $to->getHeaderLine('Location'));
        self::assertSame('', (string) $to->getBody());

        self::assertSame(303, $responder->respond(Redirect::to('/done', 303))->getStatusCode());
        self::assertSame(301, $responder->respond(Redirect::to('/done'), 301)->getStatusCode(), '#[Status] wins');

        $route = $responder->respond(Redirect::route('users.show', ['id' => 5, 'tab' => 'x'], 307));
        self::assertSame(307, $route->getStatusCode());
        self::assertSame('/api/v1/users/5?tab=x', $route->getHeaderLine('Location'));

        self::assertSame(308, $responder->redirect('/x', 308)->getStatusCode());

        try {
            Redirect::to('/x', 200);
            self::fail('status accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('3xx', $e->getMessage());
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('needs a Router');
        new Responder($factory, $factory)->respond(Redirect::route('users.show', ['id' => 1]));
    }

    public function testRedirectorRefusesARouteWithoutTheOption(): void
    {
        $this->expectException(\LogicException::class);
        new Redirector(new Psr17Factory())->redirect(
            new Route('/x', ['GET'], [Redirector::class, 'redirect']),
            new \Keel\Routing\RouteMatch(new Route('/x', ['GET'], [Redirector::class, 'redirect']), []),
        );
    }
}
