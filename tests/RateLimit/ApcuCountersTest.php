<?php

declare(strict_types=1);

namespace Keel\Tests\RateLimit;

use Keel\RateLimit\ApcuCounters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Runs only with APCu loaded and enabled for the CLI (apc.enable_cli=1), for example
 * php -d extension=apcu -d apc.enable_cli=1 vendor/bin/phpunit tests/RateLimit.
 */
#[CoversClass(ApcuCounters::class)]
#[RequiresPhpExtension('apcu')]
final class ApcuCountersTest extends TestCase
{
    protected function setUp(): void
    {
        if (!ApcuCounters::available()) {
            self::markTestSkipped('APCu is not enabled for the CLI (apc.enable_cli=1)');
        }
        apcu_clear_cache();
    }

    public function testAFreshCounterStartsAtOneAndCarriesTheTtl(): void
    {
        $counters = new ApcuCounters('keel-test:');

        self::assertSame(1, $counters->increment('fresh', 60), 'a key that does not exist yet is created, never read as exhausted');
        self::assertSame(2, $counters->increment('fresh', 60));
        $info = apcu_key_info('keel-test:fresh');
        self::assertIsArray($info);
        self::assertSame(60, $info['ttl'] ?? null);
    }

    public function testAnExpiredCounterStartsOverAndAnUnusableEntryFailsClosed(): void
    {
        $counters = new ApcuCounters('keel-test:');

        self::assertSame(1, $counters->increment('short', 1));
        sleep(2);
        self::assertSame(1, $counters->increment('short', 1), 'the window expired, the counter restarted');

        apcu_store('keel-test:text', 'not a number', 60);
        self::assertSame(\PHP_INT_MAX, $counters->increment('text', 60), 'what APCu cannot increment reads as exhausted');
    }
}
