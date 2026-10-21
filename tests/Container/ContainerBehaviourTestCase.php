<?php

declare(strict_types=1);

namespace Keel\Tests\Container;

use Keel\Container\ContainerInterface;
use Keel\Container\Definitions;
use Keel\Container\Exception\CircularDependencyException;
use Keel\Container\Exception\ContainerException;
use Keel\Container\Exception\NotFoundException;
use Keel\Container\Ref;
use Keel\Container\Reflection\Instantiator;
use Keel\Tests\Container\Fixtures\AbstractThing;
use Keel\Tests\Container\Fixtures\AlphaHandler;
use Keel\Tests\Container\Fixtures\BetaHandler;
use Keel\Tests\Container\Fixtures\Clock;
use Keel\Tests\Container\Fixtures\Connection;
use Keel\Tests\Container\Fixtures\ConnectionFactory;
use Keel\Tests\Container\Fixtures\Counter;
use Keel\Tests\Container\Fixtures\CycleA;
use Keel\Tests\Container\Fixtures\Database;
use Keel\Tests\Container\Fixtures\Dispatcher;
use Keel\Tests\Container\Fixtures\ExplodingFactory;
use Keel\Tests\Container\Fixtures\ExplodingReset;
use Keel\Tests\Container\Fixtures\FrozenClock;
use Keel\Tests\Container\Fixtures\Handler;
use Keel\Tests\Container\Fixtures\HeavyService;
use Keel\Tests\Container\Fixtures\HiddenFactory;
use Keel\Tests\Container\Fixtures\HttpConfig;
use Keel\Tests\Container\Fixtures\LazyCache;
use Keel\Tests\Container\Fixtures\Logger;
use Keel\Tests\Container\Fixtures\Mailer;
use Keel\Tests\Container\Fixtures\Mode;
use Keel\Tests\Container\Fixtures\Newsletter;
use Keel\Tests\Container\Fixtures\NodeA;
use Keel\Tests\Container\Fixtures\NodeB;
use Keel\Tests\Container\Fixtures\NullableDeps;
use Keel\Tests\Container\Fixtures\RequestCache;
use Keel\Tests\Container\Fixtures\Server;
use Keel\Tests\Container\Fixtures\SystemClock;
use Keel\Tests\Container\Fixtures\Unresolvable;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Behaviour every container implementation must share. Subclasses only decide how the
 * container is built from definitions.
 */
abstract class ContainerBehaviourTestCase extends TestCase
{
    abstract protected function createContainer(Definitions $definitions, bool $autowire = true): ContainerInterface;

    protected function setUp(): void
    {
        HeavyService::$constructed = 0;
        Connection::$opened = 0;
    }

    public function testAutowiresConstructorGraphWithSharedServices(): void
    {
        $container = $this->createContainer(new Definitions());

        $newsletter = $container->get(Newsletter::class);

        self::assertSame($container->get(Logger::class), $newsletter->logger);
        self::assertSame($newsletter->logger, $newsletter->mailer->logger);
        self::assertSame($newsletter, $container->get(Newsletter::class));
    }

    public function testTransientAttributeCreatesNewInstanceEveryTime(): void
    {
        $container = $this->createContainer(new Definitions());

        self::assertNotSame($container->get(Counter::class), $container->get(Counter::class));
    }

    public function testTransientDefinitionOverridesDefault(): void
    {
        $definitions = new Definitions();
        $definitions->set(Logger::class)->transient();
        $container = $this->createContainer($definitions);

        self::assertNotSame($container->get(Logger::class), $container->get(Logger::class));
    }

    public function testParametersInjectDefaultsAndOptionalDependencies(): void
    {
        $definitions = new Definitions();
        $definitions->parameter('db.dsn', 'sqlite::memory:');
        $container = $this->createContainer($definitions);

        $database = $container->get(Database::class);

        self::assertSame('sqlite::memory:', $database->dsn);
        self::assertSame('sqlite::memory:', $container->get('db.dsn'));
        self::assertSame(5, $database->timeout);
        self::assertSame($container->get(Logger::class), $database->logger, 'optional class is injected when autowirable');
        self::assertNull($database->clock, 'optional interface without alias stays null');
        self::assertSame(Mode::ReadWrite, $database->mode);
    }

    public function testOptionalInterfaceIsInjectedOnceAliased(): void
    {
        $definitions = new Definitions();
        $definitions->parameter('db.dsn', 'x');
        $definitions->set(SystemClock::class);
        $container = $this->createContainer($definitions);

        self::assertInstanceOf(SystemClock::class, $container->get(Database::class)->clock);
    }

    public function testAliasAttributeResolvesInterfaceToSameInstance(): void
    {
        $definitions = new Definitions();
        $definitions->set(SystemClock::class);
        $container = $this->createContainer($definitions);

        self::assertTrue($container->has(Clock::class));
        self::assertSame($container->get(SystemClock::class), $container->get(Clock::class));
    }

    public function testExplicitAliasWinsOverAttributeAlias(): void
    {
        $definitions = new Definitions();
        $definitions->set(SystemClock::class);
        $definitions->set('frozen', FrozenClock::class)->argument('timestamp', 42);
        $definitions->alias(Clock::class, 'frozen');
        $container = $this->createContainer($definitions);

        $clock = $container->get(Clock::class);

        self::assertInstanceOf(FrozenClock::class, $clock);
        self::assertSame(42, $clock->now());
    }

    public function testTaggedServicesAreInjectedInRegistrationOrder(): void
    {
        $definitions = new Definitions();
        $definitions->set(BetaHandler::class);
        $definitions->set(AlphaHandler::class);
        $container = $this->createContainer($definitions);

        self::assertSame(['beta', 'alpha'], $container->get(Dispatcher::class)->names());
        self::assertCount(1, $container->tagged('audited'));
        self::assertInstanceOf(BetaHandler::class, $container->tagged('audited')[0]);
        self::assertSame([], $container->tagged('unknown'));
    }

    public function testLazyGhostDefersConstructionUntilFirstUse(): void
    {
        $container = $this->createContainer(new Definitions());

        $service = $container->get(HeavyService::class);

        self::assertTrue(Instantiator::isUninitializedLazy($service));
        self::assertSame(0, HeavyService::$constructed);

        self::assertSame('done', $service->work());

        self::assertFalse(Instantiator::isUninitializedLazy($service));
        self::assertSame(1, HeavyService::$constructed);
        self::assertSame(['heavy constructed', 'work'], $container->get(Logger::class)->lines);
        self::assertSame($service, $container->get(HeavyService::class));
    }

    public function testLazyFactoryProducesProxy(): void
    {
        $definitions = new Definitions();
        $definitions->parameter('db.dsn', 'pgsql://db');
        $definitions->set(Connection::class)->factory(ConnectionFactory::class . '::create')->lazy();
        $container = $this->createContainer($definitions);

        $connection = $container->get(Connection::class);

        self::assertSame(0, Connection::$opened);
        self::assertSame('pgsql://db', $connection->dsn);
        self::assertSame(1, Connection::$opened);
    }

    public function testResetCallsResettableServicesAndSkipsUninitializedLazyOnes(): void
    {
        $container = $this->createContainer(new Definitions());
        $cache = $container->get(RequestCache::class);
        $cache->items['user'] = 1;
        $lazy = $container->get(LazyCache::class);

        $container->reset();

        self::assertSame([], $cache->items);
        self::assertSame(1, $cache->resets);
        self::assertTrue(Instantiator::isUninitializedLazy($lazy), 'reset must not initialize lazy services');

        self::assertSame(0, $lazy->resets);
        $container->reset();
        self::assertSame(1, $lazy->resets, 'initialized lazy services are reset like any other');
    }

    public function testCircularDependencyIsReported(): void
    {
        $container = $this->createContainer(new Definitions());

        $this->expectException(CircularDependencyException::class);
        $this->expectExceptionMessage(CycleA::class . ' -> ' . Fixtures\CycleB::class . ' -> ' . CycleA::class);

        $container->get(CycleA::class);
    }

    public function testLazyServiceBreaksCycle(): void
    {
        $container = $this->createContainer(new Definitions());

        $b = $container->get(NodeB::class);

        self::assertInstanceOf(NodeA::class, $b->a);
        self::assertSame($b, $b->a->b);
    }

    public function testStaticFactoryWithInjectedParameter(): void
    {
        $definitions = new Definitions();
        $definitions->parameter('db.dsn', 'mysql://db');
        $definitions->set(Connection::class)->factory([ConnectionFactory::class, 'create']);
        $container = $this->createContainer($definitions);

        $connection = $container->get(Connection::class);

        self::assertSame('mysql://db', $connection->dsn);
        self::assertSame($container->get(Logger::class), $connection->logger);
    }

    public function testInstanceMethodFactoryTakesOwnerFromContainer(): void
    {
        $definitions = new Definitions();
        $definitions->parameter('db.dsn', 'mysql://db');
        $definitions->set(Connection::class)->factory([ConnectionFactory::class, 'open']);
        $container = $this->createContainer($definitions);

        self::assertSame('mysql://db?pool', $container->get(Connection::class)->dsn);
    }

    public function testArgumentOverrideWithRefAndValue(): void
    {
        $definitions = new Definitions();
        $definitions->set('special', Logger::class);
        $definitions->set('mailer2', Mailer::class)->argument('logger', new Ref('special'));
        $definitions->set('db2', Database::class)->arguments(['dsn' => 'literal', 'timeout' => 30, 'mode' => Mode::ReadOnly]);
        $container = $this->createContainer($definitions);

        $mailer = $container->get('mailer2');
        self::assertInstanceOf(Mailer::class, $mailer);
        self::assertSame($container->get('special'), $mailer->logger);
        self::assertNotSame($container->get(Logger::class), $mailer->logger);

        $database = $container->get('db2');
        self::assertInstanceOf(Database::class, $database);
        self::assertSame('literal', $database->dsn);
        self::assertSame(30, $database->timeout);
        self::assertSame(Mode::ReadOnly, $database->mode);
    }

    public function testRegisteredInstanceIsReturnedAsIs(): void
    {
        $config = new HttpConfig('example.test', 443);
        $definitions = new Definitions();
        $definitions->instance(HttpConfig::class, $config);
        $container = $this->createContainer($definitions);

        self::assertSame($config, $container->get(HttpConfig::class));
        self::assertSame($config, $container->get(Server::class)->config);
    }

    public function testArrayAndEnumParametersAreReturnedUnchanged(): void
    {
        $definitions = new Definitions();
        $definitions->parameter('list', ['a' => 1, 'b' => [1, 2, 'x' => null], 'c' => 1.5]);
        $definitions->parameter('mode', Mode::ReadOnly);
        $container = $this->createContainer($definitions);

        self::assertSame(['a' => 1, 'b' => [1, 2, 'x' => null], 'c' => 1.5], $container->get('list'));
        self::assertSame(Mode::ReadOnly, $container->get('mode'));
    }

    public function testHasReportsWhatGetCanResolve(): void
    {
        $definitions = new Definitions();
        $definitions->parameter('db.dsn', 'x');
        $container = $this->createContainer($definitions);

        self::assertTrue($container->has(Logger::class));
        self::assertTrue($container->has('db.dsn'));
        self::assertFalse($container->has(Handler::class));
        self::assertFalse($container->has(AbstractThing::class));
        self::assertFalse($container->has('nope'));
    }

    public function testFailedInstantiationDoesNotPoisonLaterAttempts(): void
    {
        $definitions = new Definitions();
        $definitions->set('exploding', Logger::class)->factory(ExplodingFactory::class . '::create');
        $definitions->set('dependent', Mailer::class)->argument('logger', new Ref('exploding'));
        $container = $this->createContainer($definitions);
        ExplodingFactory::$attempts = 0;

        foreach (['exploding', 'dependent'] as $id) {
            try {
                $container->get($id);
                self::fail('expected the factory failure');
            } catch (\RuntimeException $e) {
                self::assertSame('factory failed', $e->getMessage(), 'the real error, never a circular-dependency report');
            }
        }

        self::assertSame(2, ExplodingFactory::$attempts, 'nothing is cached after a failure');
        self::assertFalse($container->has('nope'));
    }

    public function testContainerInjectsItself(): void
    {
        $container = $this->createContainer(new Definitions());

        self::assertSame($container, $container->get(ContainerInterface::class));
        self::assertSame($container, $container->get(\Psr\Container\ContainerInterface::class));
        self::assertTrue($container->has(\Psr\Container\ContainerInterface::class));
    }

    public function testUnknownIdThrowsNotFound(): void
    {
        $container = $this->createContainer(new Definitions());

        $this->expectException(NotFoundException::class);
        $container->get('nope');
    }

    public function testInterfaceWithoutAliasThrowsNotFound(): void
    {
        $container = $this->createContainer(new Definitions());

        $this->expectException(NotFoundException::class);
        $container->get(Handler::class);
    }

    public function testAbstractClassThrowsNotFound(): void
    {
        $container = $this->createContainer(new Definitions());

        $this->expectException(NotFoundException::class);
        $container->get(AbstractThing::class);
    }

    public function testUnresolvableScalarParameterIsExplained(): void
    {
        $container = $this->createContainer(new Definitions());

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot resolve parameter $name of ' . Unresolvable::class . '::__construct');
        $container->get(Unresolvable::class);
    }

    public function testMissingDependencyIsAContainerExceptionWithTheChainNotANotFound(): void
    {
        $definitions = new Definitions();
        $definitions->set('broken', Mailer::class)->argument('logger', new Ref('missing'));
        $container = $this->createContainer($definitions);
        self::assertTrue($container->has('broken'));

        try {
            $container->get('broken');
            self::fail('expected a ContainerException');
        } catch (ContainerException $exception) {
            self::assertNotInstanceOf(NotFoundExceptionInterface::class, $exception, 'PSR-11: only the requested id itself can be "not found"');
            self::assertSame('Service "missing" is not registered and cannot be autowired (resolution chain: broken)', $exception->getMessage());
        }
    }

    public function testNullableClassParameterWithoutDefaultIsOptional(): void
    {
        $container = $this->createContainer(new Definitions());

        $service = $container->get(NullableDeps::class);

        self::assertNull($service->clock, 'interface without alias: null, like a nullable scalar');
        self::assertSame($container->get(Logger::class), $service->logger, 'autowirable class: injected');
    }

    public function testServiceWinsOverParameterWithTheSameId(): void
    {
        $definitions = new Definitions();
        $definitions->set('shared-id', Logger::class);
        $definitions->parameter('shared-id', 'scalar');
        $container = $this->createContainer($definitions);

        self::assertInstanceOf(Logger::class, $container->get('shared-id'), 'lookup order is instances, services, parameters in both containers');
        self::assertTrue($container->has('shared-id'));
    }

    public function testResetVisitsEveryServiceEvenWhenOneFailsAndReportsTheFailure(): void
    {
        $definitions = new Definitions();
        $definitions->set('exploding', ExplodingReset::class);
        $definitions->set(RequestCache::class);
        $container = $this->createContainer($definitions);
        $container->get('exploding');
        $cache = $container->get(RequestCache::class);

        try {
            $container->reset();
            self::fail('expected a ContainerException');
        } catch (ContainerException $exception) {
            self::assertSame('1 service(s) failed to reset: boom (RuntimeException)', $exception->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        }

        self::assertSame(1, $cache->resets, 'services after the failing one are still reset');
    }

    public function testAliasLoopIsRejectedWhenTheContainerIsBuilt(): void
    {
        $definitions = new Definitions();
        $definitions->alias('a', 'b');
        $definitions->alias('b', 'a');

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Alias loop detected');
        $this->createContainer($definitions);
    }

    public function testAliasCollidingWithADefinitionIdIsRejected(): void
    {
        $definitions = new Definitions();
        $definitions->set('svc', Logger::class);
        // Declared after set(): only the final aliases() pass can notice the collision.
        $definitions->set(Counter::class)->alias('svc');

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('"svc" is both an alias and a definition id');
        $this->createContainer($definitions);
    }

    public function testImplementationRegisteredUnderItsOwnAliasIdWorks(): void
    {
        $definitions = new Definitions();
        $definitions->set(Clock::class, SystemClock::class); // SystemClock carries #[Alias(Clock::class)]
        $container = $this->createContainer($definitions);

        self::assertInstanceOf(SystemClock::class, $container->get(Clock::class));
    }


    /**
     * Reflection could invoke a private method; generated code cannot. Both refuse it the same way
     * (the compiled container at compile time, the runtime container when the service is built).
     */
    public function testNonPublicFactoryMethodsAreRejected(): void
    {
        foreach ([['make', HiddenFactory::class . '::make'], ['open', [HiddenFactory::class, 'open']]] as [$method, $factory]) {
            $definitions = new Definitions();
            $definitions->set('hidden', Logger::class)->factory($factory);

            try {
                $this->createContainer($definitions)->get('hidden');
                self::fail('accepted the non-public factory ' . $method);
            } catch (ContainerException $e) {
                self::assertSame(sprintf('Factory %s::%s of "hidden" must be public', HiddenFactory::class, $method), $e->getMessage());
            }
        }
    }


    /**
     * autowire: false means the same thing in both containers: only registered definitions resolve.
     * The compiled container lists the classes it autowired at compile time and sets them aside.
     */
    public function testAutowireOffResolvesRegisteredDefinitionsOnly(): void
    {
        $definitions = new Definitions();
        $definitions->parameter('db.dsn', 'x');
        $definitions->set(Database::class);
        $definitions->set(NullableDeps::class);
        $definitions->set(Mailer::class);
        $container = $this->createContainer($definitions, autowire: false);

        self::assertFalse($container->has(Logger::class));
        self::assertNull($container->get(Database::class)->logger, 'optional dependency with a default stays at the default');
        self::assertNull($container->get(NullableDeps::class)->logger, 'nullable dependency stays null');

        try {
            $container->get(Mailer::class);
            self::fail('a required unregistered dependency must not be built');
        } catch (ContainerException $e) {
            self::assertSame(sprintf('Service "%s" is not registered and cannot be autowired (resolution chain: %s)', Logger::class, Mailer::class), $e->getMessage());
        }

        $definitions->set(Logger::class);
        $container = $this->createContainer($definitions, autowire: false);
        self::assertInstanceOf(Logger::class, $container->get(Mailer::class)->logger, 'registered: resolved with autowiring off');
        self::assertSame($container->get(Logger::class), $container->get(Database::class)->logger);
    }


    /**
     * The one-line message is unchanged; the structured fields and report() lay the failure out
     * for consoles: which dependency, why, whether autowiring was on, and the resolution path.
     */
    public function testUnresolvableDependencyIsReportedWithDependencyReasonAndPath(): void
    {
        $definitions = new Definitions();
        $definitions->set(Newsletter::class);
        $definitions->set(Mailer::class);
        $container = $this->createContainer($definitions, autowire: false);

        try {
            $container->get(Newsletter::class);
            self::fail('expected a ContainerException');
        } catch (ContainerException $e) {
            self::assertSame(Logger::class, $e->dependency);
            self::assertSame('autowiring is disabled and the class is not registered', $e->reason);
            self::assertFalse($e->autowire);
            self::assertSame([Newsletter::class, Mailer::class], $e->chain);
            self::assertSame(implode("\n", [
                'Cannot resolve service ' . Mailer::class,
                '',
                'Dependency: ' . Logger::class,
                'Reason: autowiring is disabled and the class is not registered',
                'Autowiring: disabled',
                '',
                'Resolution path:',
                Newsletter::class . ' -> ' . Mailer::class . ' -> ' . Logger::class,
            ]), $e->report());
        }

        $definitions = new Definitions();
        $definitions->set(Database::class)->argument('clock', new Ref(Clock::class));
        $definitions->parameter('db.dsn', 'x');
        $container = $this->createContainer($definitions);
        try {
            $container->get(Database::class);
            self::fail('expected a ContainerException');
        } catch (ContainerException $e) {
            self::assertSame(Clock::class, $e->dependency);
            self::assertSame('it is an interface: register an implementation and alias the interface to it', $e->reason);
            self::assertTrue($e->autowire);
        }

        try {
            $container->get(AbstractThing::class);
            self::fail('expected a NotFoundException');
        } catch (NotFoundException $e) {
            self::assertSame('it is an abstract class: register a concrete implementation and alias it', $e->reason);
            self::assertSame([], $e->chain);
            self::assertSame("Cannot resolve service " . AbstractThing::class . "\n\nReason: it is an abstract class: register a concrete implementation and alias it\nAutowiring: enabled", $e->report());
        }

        try {
            $container->get('nope');
            self::fail('expected a NotFoundException');
        } catch (NotFoundException $e) {
            self::assertSame('no service or class has this id', $e->reason);
        }

        self::assertSame('plain', new ContainerException('plain')->report(), 'without structure the report is the message');
    }
}
