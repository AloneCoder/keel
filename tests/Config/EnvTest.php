<?php

declare(strict_types=1);

namespace Keel\Tests\Config;

use Keel\Config\AppConfig;
use Keel\Config\Env;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Env::class)]
#[CoversClass(AppConfig::class)]
final class EnvTest extends TestCase
{
    public function testParsesDotenvFileWithQuotesCommentsAndPrecedence(): void
    {
        $file = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/.env';
        @mkdir(dirname($file), 0o775, true);
        file_put_contents($file, <<<'ENV'
            # comment
            APP_ENV=prod
            export APP_DEBUG=1
            NAME="Keel \"quoted\""
            SINGLE='a # not a comment'
            TRAILING=value # comment
            EMPTY=
            INVALID LINE
            ENV);

        $env = Env::fromFile($file, new Env(['APP_ENV' => 'test', 'EXTRA' => 'x']));

        self::assertSame('test', $env->get('APP_ENV'), 'base values win over the file');
        self::assertTrue($env->bool('APP_DEBUG'));
        self::assertSame('Keel "quoted"', $env->get('NAME'));
        self::assertSame('a # not a comment', $env->get('SINGLE'));
        self::assertSame('value', $env->get('TRAILING'));
        self::assertSame('', $env->get('EMPTY'));
        self::assertSame('x', $env->get('EXTRA'));
        self::assertFalse($env->has('INVALID LINE'));
    }

    public function testTypedAccessors(): void
    {
        $env = new Env(['PORT' => '8080', 'FLAG' => 'yes', 'OFF' => 'false', 'TEXT' => 'abc']);

        self::assertSame(8080, $env->int('PORT'));
        self::assertSame(5, $env->int('TEXT', 5));
        self::assertTrue($env->bool('FLAG'));
        self::assertFalse($env->bool('OFF', true));
        self::assertTrue($env->bool('MISSING', true));
        self::assertSame('abc', $env->require('TEXT'));
        self::assertSame('9', $env->with('PORT', '9')->get('PORT'));
        self::assertNull($env->get('MISSING'));

        $this->expectException(\RuntimeException::class);
        $env->require('MISSING');
    }

    public function testGlobalsSnapshotContainsStringsOnly(): void
    {
        $_ENV['KEEL_TEST_VALUE'] = 'from-env';
        $_SERVER['KEEL_TEST_ARRAY'] = ['not', 'a', 'string'];

        $env = Env::fromGlobals();

        self::assertSame('from-env', $env->get('KEEL_TEST_VALUE'));
        self::assertFalse($env->has('KEEL_TEST_ARRAY'));
        unset($_ENV['KEEL_TEST_VALUE'], $_SERVER['KEEL_TEST_ARRAY']);
    }

    public function testAppConfigFromEnv(): void
    {
        $dev = AppConfig::fromEnv('/p', new Env(['APP_ENV' => 'dev']));
        self::assertTrue($dev->debug, 'dev is debug by default');
        self::assertSame('/p/var/cache/dev', $dev->cacheDir());

        $prod = AppConfig::fromEnv('/p', new Env([]));
        self::assertSame('prod', $prod->env);
        self::assertFalse($prod->debug);

        self::assertTrue(AppConfig::fromEnv('/p', new Env(['APP_DEBUG' => '1']))->debug);
    }

    public function testMissingFileIsReported(): void
    {
        $this->expectException(\RuntimeException::class);
        Env::fromFile('/nonexistent/.env');
    }
}
