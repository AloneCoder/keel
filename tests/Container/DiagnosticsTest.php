<?php

declare(strict_types=1);

namespace Keel\Tests\Container;

use Keel\Container\ContainerInterface;
use Keel\Container\Definitions;
use Keel\Container\Diagnostics;
use Keel\Container\Exception\ContainerException;
use Keel\Container\Ref;
use Keel\Tests\Container\Fixtures\Clock;
use Keel\Tests\Container\Fixtures\Connection;
use Keel\Tests\Container\Fixtures\ConnectionFactory;
use Keel\Tests\Container\Fixtures\Database;
use Keel\Tests\Container\Fixtures\HiddenFactory;
use Keel\Tests\Container\Fixtures\Logger;
use Keel\Tests\Container\Fixtures\Mailer;
use Keel\Tests\Container\Fixtures\Newsletter;
use Keel\Tests\Container\Fixtures\SystemClock;
use Keel\Tests\Container\Fixtures\Unresolvable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Diagnostics::class)]
final class DiagnosticsTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function messages(Definitions $definitions, bool $autowire = true): array
    {
        return array_map(static fn(ContainerException $e): string => $e->getMessage(), new Diagnostics($definitions, $autowire)->check());
    }

    public function testACompleteGraphHasNoProblems(): void
    {
        $definitions = new Definitions();
        $definitions->parameter('db.dsn', 'x');
        $definitions->set(Newsletter::class);
        $definitions->set(SystemClock::class);
        $definitions->set(Database::class)->argument('clock', new Ref(Clock::class));
        $definitions->set(Connection::class)->factory([ConnectionFactory::class, 'open']);
        $definitions->set('with-container', WantsContainer::class);

        self::assertSame([], self::messages($definitions));
    }

    public function testMissingDependenciesAreReportedWithTheResolutionPath(): void
    {
        $definitions = new Definitions();
        $definitions->set('broken', Mailer::class)->argument('logger', new Ref('missing'));
        $definitions->set(Database::class)->argument('clock', new Ref(Clock::class));
        $definitions->parameter('db.dsn', 'x');
        $definitions->alias('ghost', 'nothing');

        $problems = new Diagnostics($definitions)->check();

        self::assertSame([
            'Service "missing" is not registered and cannot be autowired (resolution chain: broken)',
            sprintf('Service "%s" is not registered and cannot be autowired (resolution chain: %s)', Clock::class, Database::class),
            'Service "nothing" is not registered and cannot be autowired (resolution chain: ghost)',
        ], array_map(static fn(ContainerException $e): string => $e->getMessage(), $problems));
        self::assertSame('it is an interface: register an implementation and alias the interface to it', $problems[1]->reason);
        self::assertStringContainsString("Resolution path:\n" . Database::class . ' -> ' . Clock::class, $problems[1]->report());
    }

    public function testAutowiringOffFollowsTheContainerContract(): void
    {
        $definitions = new Definitions();
        $definitions->set(Newsletter::class);
        $definitions->parameter('db.dsn', 'x');
        $definitions->set(Database::class);

        self::assertSame([], self::messages($definitions), 'autowiring builds Mailer and Logger');
        self::assertSame([
            sprintf('Service "%s" is not registered and cannot be autowired (resolution chain: %s)', Mailer::class, Newsletter::class),
            sprintf('Service "%s" is not registered and cannot be autowired (resolution chain: %s)', Logger::class, Newsletter::class),
        ], self::messages($definitions, autowire: false), 'required dependencies fail, the optional Logger of Database silently stays null');
    }

    public function testFactoryAndConstructorProblemsAreCollectedWithoutInstantiating(): void
    {
        $definitions = new Definitions();
        $definitions->parameter('db.dsn', 'x');
        $definitions->set('hidden', Logger::class)->factory(HiddenFactory::class . '::make');
        $definitions->set('owned', Connection::class)->factory([ConnectionFactory::class, 'open']);
        $definitions->set(Unresolvable::class);
        $definitions->set('missing-method', Logger::class)->factory(ConnectionFactory::class . '::nope');

        $messages = self::messages($definitions, autowire: false);

        self::assertCount(4, $messages);
        self::assertSame(sprintf('Factory %s::make of "hidden" must be public', HiddenFactory::class), $messages[0]);
        self::assertSame(sprintf('Service "%s" is not registered and cannot be autowired (resolution chain: owned)', ConnectionFactory::class), $messages[1], 'the owner of an instance-method factory must be resolvable too');
        self::assertStringStartsWith('Cannot resolve parameter $name', $messages[2]);
        self::assertSame(sprintf('Factory %s::nope of "missing-method" does not exist', ConnectionFactory::class), $messages[3]);
    }

    public function testEveryServiceIsVisitedOnceAndCyclesDoNotLoop(): void
    {
        $definitions = new Definitions();
        $definitions->alias('a', 'b');
        $definitions->alias('b', 'a');
        $definitions->set(Newsletter::class);
        $definitions->set(Mailer::class);
        $definitions->set(Logger::class)->tag('loggers');

        $messages = self::messages($definitions);

        self::assertSame(['Alias loop detected at "b"', 'Alias loop detected at "a"'], $messages, 'the loop is reported from each alias, nothing else repeats and nothing hangs');
    }
}

final class WantsContainer
{
    public function __construct(public readonly ContainerInterface $container) {}
}
