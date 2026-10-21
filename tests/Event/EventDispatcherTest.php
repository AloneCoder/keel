<?php

declare(strict_types=1);

namespace Keel\Tests\Event;

use Keel\Container\Container;
use Keel\Container\Definitions;
use Keel\Event\Attribute\Listener;
use Keel\Event\EventDispatcher;
use Keel\Event\ListenerProvider;
use Keel\Tests\App\Fixtures\Project\Src\Greeted;
use Keel\Tests\App\Fixtures\Project\Src\GreetingLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventDispatcher::class)]
#[CoversClass(ListenerProvider::class)]
#[CoversClass(Listener::class)]
final class EventDispatcherTest extends TestCase
{
    public function testPriorityThenRegistrationOrderAndInheritance(): void
    {
        $calls = [];
        $provider = new ListenerProvider();
        $provider->add(Greeted::class, static function (Greeted $e) use (&$calls): void {
            $calls[] = 'default-1';
        });
        $provider->add(Greeted::class, static function (Greeted $e) use (&$calls): void {
            $calls[] = 'high';
        }, 10);
        $provider->add(Greeted::class, static function (Greeted $e) use (&$calls): void {
            $calls[] = 'default-2';
        });
        $provider->add(\Stringable::class, static function (object $e) use (&$calls): void {
            $calls[] = 'stringable';
        });
        $provider->add(\stdClass::class, static function (object $e) use (&$calls): void {
            $calls[] = 'other';
        });

        $event = new Greeted('Ann');
        self::assertSame($event, new EventDispatcher($provider)->dispatch($event));
        self::assertSame(['high', 'default-1', 'default-2'], $calls);
    }

    public function testStoppableEventsStopPropagation(): void
    {
        $event = new StoppableEvent();
        $provider = new ListenerProvider();
        $provider->add(StoppableEvent::class, static function (StoppableEvent $e): void {
            $e->seen++;
            $e->stopped = true;
        });
        $provider->add(StoppableEvent::class, static function (StoppableEvent $e): void {
            $e->seen++;
        });

        new EventDispatcher($provider)->dispatch($event);

        self::assertSame(1, $event->seen);
    }

    public function testAttributeListenersAreInstantiatedFromTheContainer(): void
    {
        $container = new Container(new Definitions());
        $provider = new ListenerProvider($container)->addClass(GreetingLog::class);

        new EventDispatcher($provider)->dispatch(new Greeted('Ann'));
        new EventDispatcher($provider)->dispatch(new Greeted('Bob'));

        self::assertSame(['Ann', 'Bob'], $container->get(GreetingLog::class)->names());
    }

    public function testClassListenersNeedAContainer(): void
    {
        $provider = new ListenerProvider()->addClass(GreetingLog::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('needs a container');
        new EventDispatcher($provider)->dispatch(new Greeted('x'));
    }

    public function testUntypedListenerParameterIsRejected(): void
    {
        $bad = new class {
            #[Listener]
            public function on(mixed $event): void {}
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must type its first parameter');
        new ListenerProvider()->addClass($bad::class);
    }
}
