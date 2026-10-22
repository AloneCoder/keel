<?php

declare(strict_types=1);

namespace Keel\Tests\Runtime;

use Keel\Container\Definitions;
use Keel\Event\EventDispatcher;
use Keel\Event\ListenerProvider;
use Keel\Kernel\Event\ResponseSent;
use Keel\Kernel\Event\WorkerStarted;
use Keel\Kernel\Event\WorkerStopping;
use Keel\Kernel\Kernel;
use Keel\Runtime\FpmRuntime;
use Keel\Runtime\FrankenPhpRuntime;
use Keel\Runtime\MemoryGuard;
use Keel\Runtime\RoadRunnerRuntime;
use Keel\Tests\Http\Fixtures\ModernController;
use Keel\Tests\Kernel\Fixtures\ArrayLogger;
use Keel\Tests\Kernel\Fixtures\Clock;
use Keel\Tests\Kernel\Fixtures\UserController;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Spiral\RoadRunner\Http\PSR7Worker;

#[CoversClass(FpmRuntime::class)]
#[CoversClass(FrankenPhpRuntime::class)]
#[CoversClass(RoadRunnerRuntime::class)]
#[CoversClass(WorkerStarted::class)]
#[CoversClass(WorkerStopping::class)]
final class WorkerLifecycleTest extends TestCase
{
    /** @var array<mixed> */
    private array $server;

    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->events = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    /**
     * @param list<class-string> $controllers
     */
    private function kernel(array $controllers = [UserController::class]): Kernel
    {
        $listeners = new ListenerProvider();
        $listeners->add(WorkerStarted::class, function (WorkerStarted $event): void {
            $this->events[] = 'started ' . $event->runtime;
        });
        $listeners->add(WorkerStopping::class, function (WorkerStopping $event): void {
            $this->events[] = sprintf('stopping %s after %d: %s', $event->runtime, $event->requests, $event->reason);
        });
        $listeners->add(ResponseSent::class, function (ResponseSent $event): void {
            $this->events[] = sprintf('sent %s %d', $event->request->getUri()->getPath(), $event->response->getStatusCode());
        });

        $definitions = new Definitions();
        $definitions->set(Clock::class);
        $definitions->instance(LoggerInterface::class, new ArrayLogger());
        $definitions->instance(ListenerProviderInterface::class, $listeners);
        $definitions->instance(EventDispatcherInterface::class, new EventDispatcher($listeners));

        return Kernel::build($definitions, $controllers);
    }

    private static function globalsFor(string $method, string $uri): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_GET = $_POST = $_COOKIE = $_FILES = [];
    }

    public function testFrankenPhpTerminatesAfterTheHandlerReturnedAndFramesTheLoop(): void
    {
        $kernel = $this->kernel();
        $emitter = new CollectingEmitter();
        $uris = ['/api/users/1', '/nope'];
        $handleRequest = function (callable $handler) use (&$uris): bool {
            $uri = array_shift($uris);
            if ($uri === null) {
                return false;
            }
            self::globalsFor('GET', $uri);
            $handler();
            $this->events[] = 'handler returned';

            return true;
        };

        new FrankenPhpRuntime(null, $handleRequest, $emitter)->run($kernel);

        self::assertSame([
            'started frankenphp',
            'handler returned', 'sent /api/users/1 200',
            'handler returned', 'sent /nope 404',
            'stopping frankenphp after 2: server',
        ], $this->events, 'ResponseSent (and deferred work) run once the response is complete');
    }

    public function testFrankenPhpStopReasons(): void
    {
        $kernel = $this->kernel();
        $forever = static function (callable $handler): bool {
            self::globalsFor('GET', '/api/users/1');
            $handler();

            return true;
        };

        new FrankenPhpRuntime(2, $forever, new CollectingEmitter())->run($kernel);
        self::assertSame('stopping frankenphp after 2: max_requests', end($this->events));

        new FrankenPhpRuntime(null, $forever, new CollectingEmitter(), null, new MemoryGuard(1))->run($kernel);
        self::assertSame('stopping frankenphp after 1: memory', end($this->events));
    }

    public function testFrankenPhpEmitFailureSendsNoResponseSent(): void
    {
        $kernel = $this->kernel();
        $emitter = new CollectingEmitter();
        $emitter->failWith = new \RuntimeException('socket closed');
        $once = true;
        $handleRequest = static function (callable $handler) use (&$once): bool {
            if (!$once) {
                return false;
            }
            $once = false;
            self::globalsFor('GET', '/api/users/1');
            $handler();

            return true;
        };

        new FrankenPhpRuntime(null, $handleRequest, $emitter)->run($kernel);

        self::assertSame(['started frankenphp', 'stopping frankenphp after 1: server'], $this->events);
    }

    public function testFpmRuntimeSendsResponseSent(): void
    {
        self::globalsFor('GET', '/api/users/3');
        new FpmRuntime(new CollectingEmitter())->run($this->kernel());

        self::assertSame(['sent /api/users/3 200'], $this->events, 'no worker events under php-fpm');
    }

    public function testRoadRunnerStreamsNonSeekableBodiesAndFramesTheLoop(): void
    {
        $goridge = new FakeRoadRunnerWorker();
        $factory = new Psr17Factory();
        $worker = new class ($goridge, $factory, $factory, $factory) extends PSR7Worker {
            /** @var list<ServerRequestInterface|null> */
            public array $queue = [];

            public function waitRequest(bool $populateServer = true): ?ServerRequestInterface
            {
                return array_shift($this->queue);
            }
        };
        $worker->queue = [new ServerRequest('GET', '/events'), new ServerRequest('GET', '/timed'), null];

        new RoadRunnerRuntime($worker)->run($this->kernel([ModernController::class]));

        $bodies = array_map(static fn($frame): string => $frame->body, $goridge->frames);
        self::assertSame([
            "id: 1\nevent: tick\ndata: {\"n\":1}\n\n",
            "data: plain text\n\n",
            ": ping\n\n",
            '',
            '{"answer":42}',
        ], $bodies, 'each event is its own frame, then an end-of-stream frame; a seekable body is one frame');
        self::assertSame([false, false, false, true, true], array_map(static fn($frame): bool => $frame->eos, $goridge->frames));
        self::assertSame(0, $worker->chunkSize, 'streaming mode is switched off again');
        self::assertSame(['started roadrunner', 'sent /events 200', 'sent /timed 200', 'stopping roadrunner after 2: server'], $this->events);
    }

    public function testRoadRunnerStopsOnTheMemoryGuard(): void
    {
        $goridge = new FakeRoadRunnerWorker();
        $factory = new Psr17Factory();
        $worker = new class ($goridge, $factory, $factory, $factory) extends PSR7Worker {
            public function waitRequest(bool $populateServer = true): ServerRequestInterface
            {
                return new ServerRequest('GET', '/api/users/1');
            }
        };

        new RoadRunnerRuntime($worker, null, new MemoryGuard(1))->run($this->kernel());

        self::assertTrue($goridge->stopped);
        self::assertSame('stopping roadrunner after 1: memory', end($this->events));
    }
}
