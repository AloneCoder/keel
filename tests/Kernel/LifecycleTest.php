<?php

declare(strict_types=1);

namespace Keel\Tests\Kernel;

use Keel\Container\Definitions;
use Keel\Event\EventDispatcher;
use Keel\Event\ListenerProvider;
use Keel\Kernel\DeferredTasks;
use Keel\Kernel\Event\ExceptionRaised;
use Keel\Kernel\Event\RequestReceived;
use Keel\Kernel\Event\ResponseSent;
use Keel\Kernel\ExceptionHandlerMiddleware;
use Keel\Kernel\Kernel;
use Keel\Kernel\Lifecycle;
use Keel\Testing\TestClient;
use Keel\Tests\Kernel\Fixtures\ArrayLogger;
use Keel\Tests\Kernel\Fixtures\LifecycleController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\Log\LoggerInterface;

#[CoversClass(Kernel::class)]
#[CoversClass(Lifecycle::class)]
#[CoversClass(DeferredTasks::class)]
#[CoversClass(ExceptionHandlerMiddleware::class)]
#[CoversClass(ListenerProvider::class)]
final class LifecycleTest extends TestCase
{
    private ArrayLogger $logger;

    private ListenerProvider $listeners;

    protected function setUp(): void
    {
        LifecycleController::$done = [];
        $this->logger = new ArrayLogger();
        $this->listeners = new ListenerProvider();
    }

    private function client(bool $events = true): TestClient
    {
        $definitions = new Definitions();
        $definitions->instance(LoggerInterface::class, $this->logger);
        if ($events) {
            $definitions->instance(ListenerProviderInterface::class, $this->listeners);
            $definitions->instance(EventDispatcherInterface::class, new EventDispatcher($this->listeners));
        }

        return TestClient::forKernel(Kernel::build($definitions, [LifecycleController::class]));
    }

    public function testRequestAndResponseEvents(): void
    {
        $seen = [];
        $this->listeners->add(RequestReceived::class, static function (RequestReceived $event) use (&$seen): void {
            $seen[] = 'received ' . $event->request->getUri()->getPath();
        });
        $this->listeners->add(ResponseSent::class, static function (ResponseSent $event) use (&$seen): void {
            $seen[] = 'sent ' . $event->response->getStatusCode();
        });
        $this->listeners->add(ExceptionRaised::class, static function (ExceptionRaised $event) use (&$seen): void {
            $seen[] = sprintf('raised %d %s', $event->status, $event->exception->getMessage());
        });
        $client = $this->client();

        $client->get('/ok')->assertOk();
        $client->get('/missing')->assertStatus(404);
        $client->get('/crash')->assertStatus(500);

        self::assertSame([
            'received /ok', 'sent 200',
            'received /missing', 'raised 404 No such thing', 'sent 404',
            'received /crash', 'raised 500 boom', 'sent 500',
        ], $seen);
    }

    public function testListenerFailures(): void
    {
        $this->listeners->add(RequestReceived::class, static function (RequestReceived $event): void {
            if ($event->request->getUri()->getPath() === '/ok') {
                throw new \LogicException('listener broke');
            }
        });
        $this->listeners->add(ExceptionRaised::class, static fn() => throw new \RuntimeException('tracker down'));
        $this->listeners->add(ResponseSent::class, static fn() => throw new \RuntimeException('audit down'));
        $client = $this->client();

        $client->get('/ok')->assertStatus(500);
        $client->get('/missing')->assertStatus(404);

        self::assertContains('error: Listener for Keel\Kernel\Event\ExceptionRaised failed: tracker down', $this->logger->records, 'a failing error reporter does not change the answer');
        self::assertContains('error: Listener for Keel\Kernel\Event\ResponseSent failed: audit down', $this->logger->records);
        self::assertContains('error: listener broke', $this->logger->records, 'a RequestReceived listener failure is answered like a handler failure');
    }

    public function testNoDispatcherMeansNoEvents(): void
    {
        $this->client(events: false)->get('/ok')->assertOk();
        self::assertSame([], $this->logger->records);

        $provider = new ListenerProvider();
        self::assertFalse($provider->hasListenersFor(ResponseSent::class));
        $provider->add(\Stringable::class, static fn() => null);
        self::assertFalse($provider->hasListenersFor(ResponseSent::class));
        $provider->add(ResponseSent::class, static fn() => null);
        self::assertTrue($provider->hasListenersFor(ResponseSent::class), 'add() clears the memo');
        $provider->add(\Throwable::class, static fn() => null);
        self::assertTrue($provider->hasListenersFor(\RuntimeException::class), 'interfaces count');
    }

    public function testDeferredTasksRunAfterTheResponse(): void
    {
        $client = $this->client(events: false);

        $client->post('/work')->assertOk()->assertJson(['pending' => 3]);

        self::assertSame(['mail', 'cache', 'follow-up'], LifecycleController::$done, 'in order, a failing task does not stop the rest, tasks deferred by tasks run too');
        self::assertSame(['error: Deferred task failed: webhook down'], $this->logger->records);

        $client->get('/ok')->assertOk();
        self::assertSame(['mail', 'cache', 'follow-up'], LifecycleController::$done, 'nothing carries over to the next request');
    }

    public function testDeferredTasksUnit(): void
    {
        $tasks = new DeferredTasks();
        $ran = [];
        $tasks->defer(static function () use (&$ran): void {
            $ran[] = 1;
        });
        $tasks->defer(static function () use (&$ran): void {
            $ran[] = 2;
        });
        self::assertSame(2, $tasks->pending());
        self::assertSame(2, $tasks->run());
        self::assertSame([1, 2], $ran);
        self::assertSame(0, $tasks->run());

        $tasks->defer(static function () use (&$ran): void {
            $ran[] = 3;
        });
        $tasks->reset();
        self::assertSame(0, $tasks->pending(), 'reset drops what never ran');
        self::assertSame([1, 2], $ran);
    }

    public function testKernelDispatchIsANoOpWithoutListeners(): void
    {
        $definitions = new Definitions();
        $definitions->instance(ListenerProviderInterface::class, $this->listeners);
        $definitions->instance(EventDispatcherInterface::class, new EventDispatcher($this->listeners));
        $kernel = Kernel::build($definitions, [LifecycleController::class]);

        $kernel->dispatch(new \ArrayObject());
        $got = null;
        $this->listeners->add(\ArrayObject::class, static function (\ArrayObject $event) use (&$got): void {
            $got = $event;
        });
        $event = new \ArrayObject(['x' => 1]);
        $kernel->dispatch($event);
        self::assertSame($event, $got);
    }
}
