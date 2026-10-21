<?php

declare(strict_types=1);

namespace Keel\Tests\Config;

use Keel\Config\AppConfig;
use Keel\Config\ConfigLoader;
use Keel\Config\Env;
use Keel\Config\HttpConfig;
use Keel\Tests\App\Fixtures\Project\Src\GreetingConfig;
use Keel\Tests\App\Fixtures\Project\Src\StampMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigLoader::class)]
final class ConfigLoaderTest extends TestCase
{
    public function testLoadsObjectsAndClosures(): void
    {
        $project = dirname(__DIR__) . '/App/Fixtures/Project';

        $configs = new ConfigLoader()->load($project . '/config', new Env(['APP_ENV' => 'test']), $project);

        self::assertInstanceOf(AppConfig::class, $configs[AppConfig::class]);
        self::assertSame('test', $configs[AppConfig::class]->env);
        self::assertSame($project, $configs[AppConfig::class]->projectDir);
        self::assertInstanceOf(HttpConfig::class, $configs[HttpConfig::class]);
        self::assertSame(StampMiddleware::class, $configs[HttpConfig::class]->middleware[0]);
        self::assertCount(5, $configs[HttpConfig::class]->middleware);
        self::assertInstanceOf(GreetingConfig::class, $configs[GreetingConfig::class]);
        self::assertSame('Hi', $configs[GreetingConfig::class]->prefix);
    }

    public function testMissingDirectoryIsEmpty(): void
    {
        self::assertSame([], new ConfigLoader()->load('/nonexistent', new Env([]), '/p'));
    }

    public function testNonObjectIsRejected(): void
    {
        $dir = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/config-bad';
        @mkdir($dir, 0o775, true);
        file_put_contents($dir . '/bad.php', '<?php return ["array"];');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must return an object');
        new ConfigLoader()->load($dir, new Env([]), '/p');
    }
}
