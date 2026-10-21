<?php

declare(strict_types=1);

namespace Keel\Tests\App;

use Keel\App\App;
use Keel\App\AppBuilder;
use Keel\Config\AppConfig;
use Keel\Config\Env;
use Keel\Container\Compiled\AbstractCompiledContainer;
use Keel\Container\Container;
use Keel\Testing\TestClient;
use Keel\Tests\App\Fixtures\Project\Src\GreetingConfig;
use Keel\Tests\App\Fixtures\Project\Src\GreetingLogInterface;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(App::class)]
#[CoversClass(AppBuilder::class)]
final class AppTest extends TestCase
{
    private const string PROJECT = __DIR__ . '/Fixtures/Project';

    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/app-' . uniqid();
    }

    private function env(string $appEnv, bool $debug): Env
    {
        return new Env(['APP_ENV' => $appEnv, 'APP_DEBUG' => $debug ? '1' : '0', 'KEEL_CACHE_DIR' => $this->cacheDir]);
    }

    public function testBootsInDebugModeAndServesRequestsWithEventsAndConfig(): void
    {
        $app = App::boot(self::PROJECT, $this->env('dev', true));

        self::assertInstanceOf(Container::class, $app->container);
        self::assertSame('dev', $app->config->env);
        self::assertSame($this->cacheDir, $app->config->cacheDir());
        self::assertSame('Hi', $app->container->get(GreetingConfig::class)->prefix);
        self::assertSame($app->env, $app->container->get(Env::class));
        self::assertSame(['Keel\Tests\App\Fixtures\Project\Src\AdminController', 'Keel\Tests\App\Fixtures\Project\Src\GreetController'], $app->controllers());

        $response = $app->kernel->handle(new ServerRequest('GET', '/greet/Ann'));
        $app->kernel->handle(new ServerRequest('GET', '/greet/Bob'));

        self::assertSame('keel', $response->getHeaderLine('X-Stamp'), 'HttpConfig middleware applied');
        self::assertSame(['greeting' => 'Hi, Ann!', 'log' => ['Ann']], json_decode((string) $response->getBody(), true));
        self::assertSame(['Ann', 'Bob'], $app->container->get(GreetingLogInterface::class)->names(), '#[Alias] from discovery works');
        self::assertFileExists($this->cacheDir . '/discovery.php', 'discovery is cached even in debug');
        self::assertFileDoesNotExist($this->cacheDir . '/routes.php');
        self::assertFileDoesNotExist($this->cacheDir . '/container.php');
    }

    public function testProductionBootWritesAndReusesCaches(): void
    {
        $first = App::boot(self::PROJECT, $this->env('prod', false));

        self::assertInstanceOf(AbstractCompiledContainer::class, $first->container);
        self::assertFileExists($this->cacheDir . '/routes.php');
        self::assertFileExists($this->cacheDir . '/container.php');
        $response = $first->kernel->handle(new ServerRequest('GET', '/greet/Zed'));
        self::assertSame(['greeting' => 'Hi, Zed!', 'log' => ['Zed']], json_decode((string) $response->getBody(), true));

        $second = App::boot(self::PROJECT, $this->env('prod', false));
        self::assertInstanceOf(AbstractCompiledContainer::class, $second->container);
        self::assertSame(200, $second->kernel->handle(new ServerRequest('GET', '/greet/Yan'))->getStatusCode());

        $this->assertOperationalEndpoints($second);
        self::assertCount(3, $second->compile());
        self::assertCount(3, $second->clearCache());
        self::assertSame([], $second->clearCache());
    }

    public function testConfigureClosureAndDefaultsWithoutConfigDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/empty-project';
        @mkdir($dir . '/src', 0o775, true);

        $app = App::boot($dir, new Env([]), static function ($definitions): void {
            $definitions->parameter('answer', 42);
        });

        self::assertSame('prod', $app->config->env);
        self::assertSame($dir . '/var/cache/prod', $app->config->cacheDir());
        self::assertSame(42, $app->container->get('answer'));
        self::assertSame(404, $app->kernel->handle(new ServerRequest('GET', '/'))->getStatusCode());
        self::assertInstanceOf(AppConfig::class, $app->configs[AppConfig::class]);
        $app->clearCache();
    }

    /**
     * Health, metrics, OpenAPI and auth wired through config/*.php, on the compiled container.
     */
    private function assertOperationalEndpoints(App $app): void
    {
        $client = TestClient::forApp($app);

        $client->get('/health')->assertOk()->assertJson(['status' => 'up', 'checks' => ['ping' => ['status' => 'up', 'detail' => 'pong']]]);
        $client->get('/health/live')->assertOk();

        $client->get('/admin')->assertProblem(401)->assertHeader('WWW-Authenticate', 'Bearer realm="api"');
        $client->get('/admin', ['Authorization' => 'Bearer secret'])->assertOk()->assertJson(['admin' => 'alice'])->assertHeader('RateLimit-Limit', '50');

        $spec = $client->get('/openapi.json')->assertOk();
        $spec->assertJsonPath('info.title', 'Fixture API');
        $spec->assertJsonPath('paths./admin.get.security', [['bearer' => []]]);
        self::assertIsArray($spec->jsonPath('paths./greet/{name}'));
        $client->get('/docs')->assertOk()->assertBodyContains('data-url="/openapi.json"');

        $metrics = $client->get('/metrics')->assertOk()->body();
        self::assertStringContainsString('http_requests_total{method="GET",route="/admin",status="200"} 1', $metrics);
        self::assertStringContainsString('http_requests_total{method="GET",route="/admin",status="401"} 1', $metrics);
    }
}
