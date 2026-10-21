<?php

declare(strict_types=1);

namespace Keel\Tests\Container;

use Keel\Container\Container;
use Keel\Container\ContainerInterface;
use Keel\Container\Definition;
use Keel\Container\Definitions;
use Keel\Container\Exception\NotFoundException;
use Keel\Container\Reflection\ClassAttributes;
use Keel\Container\Reflection\Instantiator;
use Keel\Container\Reflection\Planner;
use Keel\Tests\Container\Fixtures\Connection;
use Keel\Tests\Container\Fixtures\ConnectionFactory;
use Keel\Tests\Container\Fixtures\Logger;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Container::class)]
#[CoversClass(Definitions::class)]
#[CoversClass(Definition::class)]
#[CoversClass(ClassAttributes::class)]
#[CoversClass(Planner::class)]
#[CoversClass(Instantiator::class)]
final class ContainerTest extends ContainerBehaviourTestCase
{
    protected function createContainer(Definitions $definitions): ContainerInterface
    {
        return new Container($definitions);
    }

    public function testClosureFactoryIsAutowired(): void
    {
        $definitions = new Definitions();
        $definitions->set(Connection::class)->factory(
            static fn(Logger $logger): Connection => new Connection('closure', $logger),
        );
        $container = new Container($definitions);

        $connection = $container->get(Connection::class);

        self::assertSame('closure', $connection->dsn);
        self::assertSame($container->get(Logger::class), $connection->logger);
    }

    public function testObjectBoundFactoryIsSupportedAtRuntime(): void
    {
        $definitions = new Definitions();
        $definitions->parameter('db.dsn', 'bound');
        $definitions->set(Connection::class)->factory([new ConnectionFactory(new Logger()), 'open']);
        $container = new Container($definitions);

        self::assertSame('bound?pool', $container->get(Connection::class)->dsn);
    }

    public function testAutowiringCanBeDisabled(): void
    {
        $definitions = new Definitions();
        $definitions->set('log', Logger::class);
        $container = new Container($definitions, autowire: false);

        self::assertInstanceOf(Logger::class, $container->get('log'));
        self::assertFalse($container->has(Logger::class));

        $this->expectException(NotFoundException::class);
        $container->get(Logger::class);
    }
}
