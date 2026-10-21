<?php

declare(strict_types=1);

namespace Keel\Tests\Console;

use Keel\App\App;
use Keel\Config\Env;
use Keel\Console\Command\CacheClearCommand;
use Keel\Console\Command\CompileCommand;
use Keel\Console\Command\RoutesCommand;
use Keel\Console\ConsoleFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

#[CoversClass(ConsoleFactory::class)]
#[CoversClass(CompileCommand::class)]
#[CoversClass(CacheClearCommand::class)]
#[CoversClass(RoutesCommand::class)]
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

    public function testDiscoveredCommandIsLoadedFromTheContainer(): void
    {
        self::assertSame(0, $this->tester->run(['command' => 'demo:hello']));
        self::assertStringContainsString('Hi from the command', $this->tester->getDisplay());
    }
}
