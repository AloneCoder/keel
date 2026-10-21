<?php

declare(strict_types=1);

namespace Keel\Tests\Http;

use Keel\Container\Container;
use Keel\Container\Definitions;
use Keel\Http\CallableHandler;
use Keel\Http\Pipeline;
use Keel\Tests\Kernel\Fixtures\ClassMiddleware;
use Keel\Tests\Kernel\Fixtures\GlobalMiddleware;
use Keel\Tests\Kernel\Fixtures\StatefulMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(Pipeline::class)]
#[CoversClass(CallableHandler::class)]
final class PipelineTest extends TestCase
{
    public function testRunsMiddlewareInOrderAndIsReusable(): void
    {
        $factory = new Psr17Factory();
        $fallback = new CallableHandler(static function (ServerRequestInterface $request) use ($factory): ResponseInterface {
            $trace = $request->getAttribute('trace');

            return $factory->createResponse()->withHeader('X-In', implode(',', array_filter(is_array($trace) ? $trace : [], is_string(...))));
        });
        $pipeline = new Pipeline([new GlobalMiddleware(), ClassMiddleware::class], $fallback, new Container(new Definitions()));

        for ($i = 0; $i < 2; $i++) {
            $response = $pipeline->handle(new ServerRequest('GET', '/'));

            self::assertSame('global,class', $response->getHeaderLine('X-In'));
            self::assertSame(['class', 'global'], $response->getHeader('X-Trace'));
        }
    }

    public function testClassNamesNeedAContainer(): void
    {
        $pipeline = new Pipeline([ClassMiddleware::class], new CallableHandler(static fn(): ResponseInterface => new Psr17Factory()->createResponse()));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no container');
        $pipeline->handle(new ServerRequest('GET', '/'));
    }

    public function testNonMiddlewareServiceIsRejected(): void
    {
        $definitions = new Definitions();
        $definitions->set('svc', \stdClass::class);
        /** @phpstan-ignore argument.type (deliberately wrong service id) */
        $pipeline = new Pipeline(['svc'], new CallableHandler(static fn(): ResponseInterface => new Psr17Factory()->createResponse()), new Container($definitions));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not a PSR-15 middleware');
        $pipeline->handle(new ServerRequest('GET', '/'));
    }


    public function testMiddlewareLifetimeBelongsToTheContainer(): void
    {
        $factory = new Psr17Factory();
        $fallback = new CallableHandler(static fn(): ResponseInterface => $factory->createResponse());

        $transient = new Definitions();
        $transient->set(StatefulMiddleware::class)->transient();
        $pipeline = new Pipeline([StatefulMiddleware::class], $fallback, new Container($transient));
        self::assertSame('1', $pipeline->handle(new ServerRequest('GET', '/'))->getHeaderLine('X-Stateful-Calls'));
        self::assertSame('1', $pipeline->handle(new ServerRequest('GET', '/'))->getHeaderLine('X-Stateful-Calls'), 'transient: a fresh instance per request, nothing carries over');

        $shared = new Definitions();
        $shared->set(StatefulMiddleware::class);
        $container = new Container($shared);
        $pipeline = new Pipeline([StatefulMiddleware::class], $fallback, $container);
        $pipeline->handle(new ServerRequest('GET', '/'));
        self::assertSame('2', $pipeline->handle(new ServerRequest('GET', '/'))->getHeaderLine('X-Stateful-Calls'), 'shared: the one container instance');
        self::assertSame(2, $container->get(StatefulMiddleware::class)->calls);
    }
}
