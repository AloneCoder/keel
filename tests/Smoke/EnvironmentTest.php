<?php

declare(strict_types=1);

namespace Keel\Tests\Smoke;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class EnvironmentTest extends TestCase
{
    public function testPhpVersionIsAtLeast84(): void
    {
        self::assertGreaterThanOrEqual(80400, \PHP_VERSION_ID);
    }

    public function testLazyGhostIsNotInitializedUntilTouched(): void
    {
        $reflection = new \ReflectionClass(LazyProbe::class);
        $probe = $reflection->newLazyGhost(static function (LazyProbe $p): void {
            $p->value = 'initialized';
        });

        self::assertTrue($reflection->isUninitializedLazyObject($probe));
        self::assertSame('initialized', $probe->value);
        self::assertFalse($reflection->isUninitializedLazyObject($probe));
    }
}

final class LazyProbe
{
    public string $value = 'default';
}
