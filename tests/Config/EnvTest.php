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
            QUOTED_COMMENT="q # not a comment" # comment
            SINGLE_COMMENT='s' # comment
            EMPTY=
            INVALID LINE
            ENV);

        $env = Env::fromFile($file, new Env(['APP_ENV' => 'test', 'EXTRA' => 'x']));

        self::assertSame('test', $env->get('APP_ENV'), 'base values win over the file');
        self::assertTrue($env->bool('APP_DEBUG'));
        self::assertSame('Keel "quoted"', $env->get('NAME'));
        self::assertSame('a # not a comment', $env->get('SINGLE'));
        self::assertSame('value', $env->get('TRAILING'));
        self::assertSame('q # not a comment', $env->get('QUOTED_COMMENT'), 'a quoted value ends at its closing quote');
        self::assertSame('s', $env->get('SINGLE_COMMENT'));
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

    public function testGlobalsSkipRequestVariablesUnlessTheEnvironmentHasThem(): void
    {
        $_SERVER['HTTP_X_KEEL'] = 'header';
        $_SERVER['REQUEST_URI'] = '/x';
        $_SERVER['REDIRECT_STATUS'] = '200';
        $_SERVER['KEEL_PLAIN'] = 'plain';
        $_ENV['HTTP_FROM_ENV'] = 'env';
        $_SERVER['HTTP_FROM_ENV'] = 'server';

        try {
            $env = Env::fromGlobals();
        } finally {
            unset($_SERVER['HTTP_X_KEEL'], $_SERVER['REQUEST_URI'], $_SERVER['REDIRECT_STATUS'], $_SERVER['KEEL_PLAIN'], $_ENV['HTTP_FROM_ENV'], $_SERVER['HTTP_FROM_ENV']);
        }

        self::assertFalse($env->has('HTTP_X_KEEL'), 'request headers are not environment');
        self::assertFalse($env->has('REQUEST_URI'));
        self::assertFalse($env->has('REDIRECT_STATUS'));
        self::assertSame('plain', $env->get('KEEL_PLAIN'), 'other $_SERVER keys are kept');
        self::assertSame('server', $env->get('HTTP_FROM_ENV'), 'a request-looking key present in $_ENV is kept, $_SERVER still wins');
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
