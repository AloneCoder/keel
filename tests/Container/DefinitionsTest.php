<?php

declare(strict_types=1);

namespace Keel\Tests\Container;

use Keel\Container\Definitions;
use Keel\Container\Exception\ContainerException;
use Keel\Container\ServiceProvider;
use Keel\Tests\Container\Fixtures\AlphaHandler;
use Keel\Tests\Container\Fixtures\BetaHandler;
use Keel\Tests\Container\Fixtures\Clock;
use Keel\Tests\Container\Fixtures\HeavyService;
use Keel\Tests\Container\Fixtures\Logger;
use Keel\Tests\Container\Fixtures\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Definitions::class)]
final class DefinitionsTest extends TestCase
{
    public function testSetReadsClassAttributes(): void
    {
        $definitions = new Definitions();

        $heavy = $definitions->set(HeavyService::class);
        $clock = $definitions->set(SystemClock::class);

        self::assertTrue($heavy->lazy);
        self::assertSame([Clock::class], $clock->aliases);
        self::assertSame([Clock::class => SystemClock::class], $definitions->aliases());
    }

    public function testFluentCallsOverrideAttributes(): void
    {
        $definitions = new Definitions();

        $heavy = $definitions->set(HeavyService::class)->lazy(false)->transient()->tag('x')->tag('x');

        self::assertFalse($heavy->lazy);
        self::assertFalse($heavy->shared);
        self::assertSame(['x'], $heavy->tags);
    }

    public function testTagsAreGroupedInRegistrationOrder(): void
    {
        $definitions = new Definitions();
        $definitions->set(BetaHandler::class);
        $definitions->set(AlphaHandler::class);

        self::assertSame([BetaHandler::class, AlphaHandler::class], $definitions->tagged('handler'));
        self::assertSame(
            ['handler' => [BetaHandler::class, AlphaHandler::class], 'audited' => [BetaHandler::class]],
            $definitions->tags(),
        );
    }

    public function testAliasChainsAreFollowed(): void
    {
        $definitions = new Definitions();
        $definitions->alias('a', 'b');
        $definitions->alias('b', Logger::class);

        self::assertSame(Logger::class, $definitions->resolveAlias('a'));
    }

    public function testAliasLoopIsDetected(): void
    {
        $definitions = new Definitions();
        $definitions->alias('a', 'b');
        $definitions->alias('b', 'a');

        $this->expectException(ContainerException::class);
        $definitions->resolveAlias('a');
    }

    public function testProvidersAreLoadedInOrder(): void
    {
        $definitions = new Definitions();
        $provider = new class implements ServiceProvider {
            public function register(Definitions $definitions): void
            {
                $definitions->set(Logger::class);
                $definitions->parameter('loaded', true);
            }
        };

        $definitions->load($provider);

        self::assertNotNull($definitions->definition(Logger::class));
        self::assertTrue($definitions->hasParameter('loaded'));
    }
}
