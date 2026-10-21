<?php

declare(strict_types=1);

namespace Keel\Tests\Console;

use Keel\App\App;
use Keel\Config\Env;
use Keel\Console\Command\CacheClearCommand;
use Keel\Console\Command\CompileCommand;
use Keel\Console\Command\OpenApiCommand;
use Keel\Console\Command\PreloadCommand;
use Keel\Console\Command\RoutesCommand;
use Keel\Console\Command\ServeCommand;
use Keel\Console\ConsoleFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

#[CoversClass(ConsoleFactory::class)]
#[CoversClass(CompileCommand::class)]
#[CoversClass(CacheClearCommand::class)]
#[CoversClass(RoutesCommand::class)]
#[CoversClass(PreloadCommand::class)]
#[CoversClass(OpenApiCommand::class)]
#[CoversClass(ServeCommand::class)]
final class ConsoleTest extends TestCase
{
    private ApplicationTester $tester;

    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/console-' . uniqid();
        $app = App::boot(__DIR__ . '/../App/Fixtures/Project', new Env(['APP_ENV' => 'dev', 'KEEL_CACHE_DIR' => $this->cacheDir]));
        $console = ConsoleFactory::create($app);
        $console->setAutoExit(false);
        $this->tester = new ApplicationTester($console);
    }

    public function testRoutesCommandListsRoutes(): void
    {
        self::assertSame(0, $this->tester->run(['command' => 'keel:routes']));
        $display = $this->tester->getDisplay();

        self::assertStringContainsString('/greet/{name}', $display);
        self::assertStringContainsString('greet', $display);
        self::assertStringContainsString('GreetController::greet', $display);
        self::assertStringContainsString('AuthGuard, RateLimitGuard', $display);
        self::assertMatchesRegularExpression('/\/admin.*\badmin\b.*50\/60s ip/', $display, 'auth roles and rate limit columns');
    }

    public function testCompileAndCacheClear(): void
    {
        self::assertSame(0, $this->tester->run(['command' => 'keel:compile']));
        self::assertStringContainsString('container.php', $this->tester->getDisplay());
        self::assertFileExists($this->cacheDir . '/container.php');
        self::assertFileExists($this->cacheDir . '/routes.php');

        self::assertSame(0, $this->tester->run(['command' => 'keel:cache-clear']));
        self::assertFileDoesNotExist($this->cacheDir . '/container.php');

        self::assertSame(0, $this->tester->run(['command' => 'keel:cache-clear']));
        self::assertStringContainsString('Nothing to remove', $this->tester->getDisplay());
    }

    public function testPreloadWritesLoadOrderedFileList(): void
    {
        self::assertSame(0, $this->tester->run(['command' => 'keel:preload']));
        $path = $this->cacheDir . '/preload.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('vendor/autoload.php', $source);
        self::assertStringContainsString('src/Kernel/Kernel.php', $source);
        self::assertStringContainsString('nyholm/psr7/src/Response.php', $source);
        self::assertStringContainsString('psr7-server/src/ServerRequestCreator.php', $source);
        self::assertStringNotContainsString('symfony/console', $source);
        self::assertStringNotContainsString('/tests/', $source);

        exec(sprintf('%s -l %s 2>&1', escapeshellarg(\PHP_BINARY), escapeshellarg($path)), $lint, $code);
        self::assertSame(0, $code, implode("\n", $lint));
    }

    public function testDiscoveredCommandIsLoadedFromTheContainer(): void
    {
        self::assertSame(0, $this->tester->run(['command' => 'demo:hello']));
        self::assertStringContainsString('Hi from the command', $this->tester->getDisplay());
    }

    public function testOpenApiCommandPrintsOrWritesTheDocument(): void
    {
        self::assertSame(0, $this->tester->run(['command' => 'keel:openapi']));
        $document = json_decode($this->tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame(['title' => 'Fixture API', 'version' => '0.1.0'], $document['info'] ?? null);
        self::assertIsArray($document['paths'] ?? null);
        self::assertArrayHasKey('/greet/{name}', $document['paths']);

        $file = $this->cacheDir . '/spec/openapi.json';
        self::assertSame(0, $this->tester->run(['command' => 'keel:openapi', '--output' => $file]));
        self::assertStringContainsString('written', $this->tester->getDisplay());
        self::assertFileExists($file);
        self::assertStringContainsString('"openapi": "3.1.0"', (string) file_get_contents($file));
    }

    public function testServeCommandBuildsTheCommandLine(): void
    {
        $php = (string) ServeCommand::command('/app', 'public', '127.0.0.1', '8080', false, false, false);
        self::assertStringContainsString("-S '127.0.0.1:8080' -t '/app/public' '/app/public/index.php'", $php);

        putenv('FRANKENPHP_BINARY=' . \PHP_BINARY);
        try {
            $worker = ServeCommand::command('/app/', 'web', '0.0.0.0', '80', true, true, true);
            self::assertNotNull($worker);
            self::assertStringContainsString("php-server --no-compress --root '/app/web' --listen '0.0.0.0:80' --worker '/app/web/index.php'", $worker);
            self::assertStringContainsString("--watch='/app/src/**/*.php'", $worker);
            self::assertStringContainsString("--watch='/app/.env'", $worker);
            self::assertStringNotContainsString('--worker', (string) ServeCommand::command('/app', 'public', 'h', '1', true, false, false));
        } finally {
            putenv('FRANKENPHP_BINARY');
        }

        putenv('FRANKENPHP_BINARY=/nonexistent/frankenphp');
        putenv('PATH=/nonexistent');
        try {
            self::assertNull(ServeCommand::command('/app', 'public', 'h', '1', true, false, false));
        } finally {
            putenv('FRANKENPHP_BINARY');
            putenv('PATH=' . (getenv('PATH') ?: '/usr/bin:/bin'));
        }
    }
}
