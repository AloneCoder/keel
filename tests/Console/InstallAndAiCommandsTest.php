<?php

declare(strict_types=1);

namespace Keel\Tests\Console;

use Keel\App\App;
use Keel\Cache\CacheProvider;
use Keel\Config\Env;
use Keel\Config\HttpConfig;
use Keel\Config\ServicesConfig;
use Keel\Console\Ai\AgentsGuide;
use Keel\Console\Ai\LlmsTxt;
use Keel\Console\Command\AiInitCommand;
use Keel\Console\Command\InstallCommand;
use Keel\Console\ConsoleFactory;
use Keel\Console\Install\Component;
use Keel\Console\Install\ConfigEditor;
use Keel\Http\Middleware\CorsMiddleware;
use Keel\Http\Middleware\SecurityHeadersMiddleware;
use Keel\OpenApi\OpenApiConfig;
use Keel\Redis\RedisProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

#[CoversClass(InstallCommand::class)]
#[CoversClass(ConfigEditor::class)]
#[CoversClass(Component::class)]
#[CoversClass(AiInitCommand::class)]
#[CoversClass(AgentsGuide::class)]
#[CoversClass(LlmsTxt::class)]
final class InstallAndAiCommandsTest extends TestCase
{
    /** Directory of the fixture controller, written and loaded once per process. */
    private static string $controllers = '';

    private string $project;

    private ApplicationTester $tester;

    public static function setUpBeforeClass(): void
    {
        if (self::$controllers !== '') {
            return;
        }
        self::$controllers = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/ai-controllers';
        if (!is_dir(self::$controllers)) {
            mkdir(self::$controllers, 0o775, true);
        }
        file_put_contents(self::$controllers . '/PingController.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Acme\Shop;

            use Keel\Http\Attribute\Deprecated;
            use Keel\OpenApi\Attribute\Operation;
            use Keel\Routing\Attribute\Get;

            final class PingController
            {
                /** @return array<string, bool> */
                #[Get('/ping')]
                #[Operation(summary: 'Liveness ping')]
                public function ping(): array
                {
                    return ['pong' => true];
                }

                /** @return array<string, bool> */
                #[Get('/old-ping')]
                #[Deprecated(sunset: '2027-01-01')]
                public function old(): array
                {
                    return ['pong' => true];
                }
            }
            PHP);
        require_once self::$controllers . '/PingController.php';
    }

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/install-' . uniqid();
        mkdir($this->project . '/src', 0o775, true);
        mkdir($this->project . '/config', 0o775, true);
        file_put_contents($this->project . '/composer.json', json_encode(['autoload' => ['psr-4' => ['Acme\\Shop\\' => 'src/']]]));
        file_put_contents($this->project . '/phpunit.xml.dist', '<phpunit/>');
        file_put_contents($this->project . '/config/discovery.php', sprintf("<?php\n\nreturn new Keel\\Config\\DiscoveryConfig([%s]);\n", var_export(self::$controllers, true)));
        $this->tester = $this->tester();
    }

    private function tester(): ApplicationTester
    {
        $console = ConsoleFactory::create(App::boot($this->project, new Env(['APP_ENV' => 'dev', 'APP_DEBUG' => '1'])));
        $console->setAutoExit(false);

        return new ApplicationTester($console);
    }

    public function testConfigEditorCreatesFiles(): void
    {
        $source = ConfigEditor::create(ServicesConfig::class, RedisProvider::class, 'providers');
        self::assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            use Keel\Config\ServicesConfig;
            use Keel\Redis\RedisProvider;

            return new ServicesConfig(providers: [
                RedisProvider::class,
            ]);

            PHP, $source);
        self::assertSame($source, ConfigEditor::add($source, ServicesConfig::class, RedisProvider::class), 'already there');
    }

    public function testConfigEditorAddsToExistingLists(): void
    {
        $multiLine = <<<'PHP'
            <?php

            declare(strict_types=1);

            use Keel\Config\ServicesConfig;
            use Keel\Redis\RedisProvider;

            // Providers, in load order.
            return new ServicesConfig([
                RedisProvider::class, // first
            ]);

            PHP;
        self::assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            use Keel\Config\ServicesConfig;
            use Keel\Redis\RedisProvider;
            use Keel\Cache\CacheProvider;

            // Providers, in load order.
            return new ServicesConfig([
                RedisProvider::class, // first
                CacheProvider::class,
            ]);

            PHP, ConfigEditor::add($multiLine, ServicesConfig::class, CacheProvider::class));

        $inline = "<?php\n\nuse Keel\\Config\\HttpConfig;\n\nreturn static fn(): HttpConfig => new HttpConfig(middleware: [\\Keel\\Http\\Middleware\\CorsMiddleware::class]);\n";
        self::assertSame($inline, ConfigEditor::add($inline, HttpConfig::class, CorsMiddleware::class), 'present as a fully qualified name');
        self::assertSame(
            "<?php\n\nuse Keel\\Config\\HttpConfig;\nuse Keel\\Http\\Middleware\\SecurityHeadersMiddleware;\n\nreturn static fn(): HttpConfig => new HttpConfig(middleware: [\\Keel\\Http\\Middleware\\CorsMiddleware::class, SecurityHeadersMiddleware::class]);\n",
            ConfigEditor::add($inline, HttpConfig::class, SecurityHeadersMiddleware::class),
        );

        $empty = "<?php\n\ndeclare(strict_types=1);\n\nreturn new \\Keel\\Config\\HttpConfig([]);\n";
        self::assertSame(
            "<?php\n\ndeclare(strict_types=1);\n\nuse Keel\\Http\\Middleware\\CorsMiddleware;\n\nreturn new \\Keel\\Config\\HttpConfig([\n    CorsMiddleware::class,\n]);\n",
            ConfigEditor::add($empty, HttpConfig::class, CorsMiddleware::class),
        );

        $clash = "<?php\n\nuse App\\CorsMiddleware;\nuse Keel\\Config\\HttpConfig;\n\nreturn new HttpConfig([CorsMiddleware::class]);\n";
        self::assertSame(
            "<?php\n\nuse App\\CorsMiddleware;\nuse Keel\\Config\\HttpConfig;\n\nreturn new HttpConfig([CorsMiddleware::class, \\Keel\\Http\\Middleware\\CorsMiddleware::class]);\n",
            ConfigEditor::add($clash, HttpConfig::class, CorsMiddleware::class),
            'a short name that is taken stays fully qualified',
        );

        $pairs = "<?php\n\nreturn new HttpConfig(middleware: [\n    [A::class, ['limit' => [1, 2]]],\n    [B::class, []] // throttled\n]);\n";
        self::assertSame(
            "<?php\n\nuse Keel\\Http\\Middleware\\CorsMiddleware;\n\nreturn new HttpConfig(middleware: [\n    [A::class, ['limit' => [1, 2]]],\n    [B::class, []], // throttled\n    CorsMiddleware::class,\n]);\n",
            ConfigEditor::add($pairs, HttpConfig::class, CorsMiddleware::class),
            'nested [class, arguments] pairs and a trailing comment',
        );
        $closingOnLastLine = "<?php\nreturn new HttpConfig([\n    A::class]);\n";
        self::assertSame(
            "<?php\n\nuse Keel\\Http\\Middleware\\CorsMiddleware;\n\nreturn new HttpConfig([\n    A::class,\n    CorsMiddleware::class,\n]);\n",
            ConfigEditor::add($closingOnLastLine, HttpConfig::class, CorsMiddleware::class),
        );
        self::assertNull(ConfigEditor::add("<?php\nreturn \$config;\n", HttpConfig::class, CorsMiddleware::class));
        self::assertNull(ConfigEditor::add("<?php\nreturn new HttpConfig(\$middleware);\n", HttpConfig::class, CorsMiddleware::class));
        self::assertNull(ConfigEditor::add("<?php\nreturn new OtherConfig([]);\n", HttpConfig::class, CorsMiddleware::class));
    }

    public function testInstallWritesConfigAndRegistersProvidersAndMiddleware(): void
    {
        self::assertSame(0, $this->tester->run(['command' => 'keel:install']));
        self::assertStringContainsString('security-headers', $this->tester->getDisplay());

        self::assertSame(0, $this->tester->run(['command' => 'keel:install', 'components' => ['security-headers', 'cache'], '--dry-run' => true]));
        self::assertStringContainsString('would wrote   config/security_headers.php', $this->tester->getDisplay());
        self::assertFileDoesNotExist($this->project . '/config/http.php');

        self::assertSame(0, $this->tester->run(['command' => 'keel:install', 'components' => ['security-headers', 'jwt'], '--no-composer' => true]));
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('wrote   config/http.php with ' . SecurityHeadersMiddleware::class, $display);
        self::assertStringContainsString('wrote   config/services.php with Keel\HttpClient\HttpClientProvider', $display);
        self::assertStringContainsString('added   ' . CacheProvider::class . ' to config/services.php', $display);
        self::assertStringContainsString('jwt: Set JWT_JWKS_URL', $display);
        foreach (['http.php', 'services.php', 'security_headers.php', 'jwt.php', 'auth.php', 'cache.php', 'http_client.php'] as $file) {
            self::assertFileExists($this->project . '/config/' . $file);
            exec(sprintf('%s -l %s 2>&1', escapeshellarg(\PHP_BINARY), escapeshellarg($this->project . '/config/' . $file)), $out, $code);
            self::assertSame(0, $code, implode("\n", $out));
        }

        // The written files load: a fresh boot sees the provider and the middleware.
        $app = App::boot($this->project, new Env(['APP_ENV' => 'dev', 'APP_DEBUG' => '1', 'JWT_JWKS_URL' => 'https://idp.test/jwks']));
        $services = $app->configs[ServicesConfig::class];
        self::assertInstanceOf(ServicesConfig::class, $services);
        self::assertSame(['Keel\HttpClient\HttpClientProvider', CacheProvider::class], $services->providers);
        $http = $app->configs[HttpConfig::class];
        self::assertInstanceOf(HttpConfig::class, $http);
        self::assertSame([SecurityHeadersMiddleware::class], $http->middleware);

        self::assertSame(0, $this->tester->run(['command' => 'keel:install', 'components' => ['cache'], '--no-composer' => true]));
        self::assertStringContainsString('kept    config/cache.php (exists)', $this->tester->getDisplay());
        self::assertStringContainsString('kept    config/services.php (already lists ' . CacheProvider::class . ')', $this->tester->getDisplay());

        self::assertSame(1, $this->tester->run(['command' => 'keel:install', 'components' => ['nope']]));
        self::assertStringContainsString('Unknown component(s): nope', $this->tester->getDisplay());

        file_put_contents($this->project . '/config/http.php', "<?php\n\nreturn \\Keel\\Config\\HttpConfig::class === '' ? null : new \\Keel\\Config\\HttpConfig(\$list ?? []);\n");
        self::assertSame(1, $this->tester->run(['command' => 'keel:install', 'components' => ['cors'], '--no-composer' => true]));
        self::assertStringContainsString('add ' . CorsMiddleware::class . '::class to it yourself', $this->tester->getDisplay());
    }

    public function testInstallRunsComposerForMissingPackages(): void
    {
        $fake = $this->project . '/fake-composer';
        file_put_contents($fake, "#!/bin/sh\necho \"\$@\" > " . escapeshellarg($this->project . '/composer-args') . "\n");
        chmod($fake, 0o755);
        $app = App::boot($this->project, new Env(['APP_ENV' => 'dev', 'APP_DEBUG' => '1']));
        $console = new \Symfony\Component\Console\Application();
        $console->setAutoExit(false);
        $console->addCommands([new InstallCommand($app, [
            'acme' => new Component('acme', 'Test component', packages: ['acme/not-installed', 'monolog/monolog'], extensions: ['no-such-extension']),
        ])]);
        $tester = new ApplicationTester($console);

        self::assertSame(0, $tester->run(['command' => 'keel:install', 'components' => ['acme'], '--composer' => $fake]));
        self::assertSame('require acme/not-installed', trim((string) file_get_contents($this->project . '/composer-args')), 'installed packages are not required again');
        self::assertStringContainsString('acme needs the PHP extension "no-such-extension"', $tester->getDisplay());

        self::assertSame(1, $tester->run(['command' => 'keel:install', 'components' => ['acme'], '--composer' => 'false']));
        self::assertStringContainsString('composer require failed; nothing else was changed', $tester->getDisplay());

        self::assertSame(0, $tester->run(['command' => 'keel:install', 'components' => ['acme'], '--no-composer' => true]));
        self::assertStringContainsString('Skipped composer require acme/not-installed', $tester->getDisplay());

        foreach (Component::all() as $name => $component) {
            self::assertSame($name, $component->name);
            foreach ($component->requires as $required) {
                self::assertArrayHasKey($required, Component::all());
            }
            foreach ($component->configs as $source) {
                self::assertStringStartsWith("<?php\n\ndeclare(strict_types=1);\n\n", $source);
            }
        }
    }

    public function testAiInitWritesAgentsGuideAndLlmsTxt(): void
    {
        self::assertSame(0, $this->tester->run(['command' => 'keel:ai:init']));
        self::assertStringContainsString('wrote AGENTS.md', $this->tester->getDisplay());

        $agents = (string) file_get_contents($this->project . '/AGENTS.md');
        self::assertStringContainsString('# AGENTS.md', $agents);
        self::assertStringContainsString('namespace `Acme\Shop\` in `src/`', $agents);
        self::assertStringContainsString('`vendor/bin/phpunit` (phpunit.xml.dist)', $agents);
        self::assertStringContainsString('Resettable', $agents);
        self::assertStringContainsString('- `GET /ping` -> `PingController::ping`', $agents);
        self::assertStringContainsString('docs/en/http.md`: HTTP and kernel', $agents);
        self::assertStringNotContainsString('phpstan', $agents, 'only tools the project has');

        $llms = (string) file_get_contents($this->project . '/public/llms.txt');
        self::assertStringStartsWith("# API\n\n> HTTP API, version 1.0.0.\n", $llms);
        self::assertStringContainsString('- `GET /ping`: Liveness ping', $llms);
        self::assertStringContainsString('- `GET /old-ping`: pingOld (deprecated, removed after 2027-01-01T00:00:00Z)', $llms);
        self::assertStringContainsString('- [OpenAPI document](/openapi.json)', $llms);

        self::assertSame(1, $this->tester->run(['command' => 'keel:ai:init']));
        self::assertStringContainsString('AGENTS.md exists; use --force to overwrite', $this->tester->getDisplay());
        self::assertSame(0, $this->tester->run(['command' => 'keel:ai:init', '--force' => true, '--llms' => 'none']));

        self::assertSame(0, $this->tester->run(['command' => 'keel:ai:init', '--stdout' => true, '--agents' => 'none']));
        self::assertStringContainsString('== public/llms.txt', $this->tester->getDisplay());

        $custom = LlmsTxt::render(['info' => ['title' => 'Shop', 'version' => '2', 'description' => "Orders\nand carts"], 'paths' => (object) []], new OpenApiConfig(specPath: null, docsPath: null));
        self::assertSame("# Shop\n\n> Orders\n> and carts\n\nRequests and responses are JSON; errors are RFC 9457 Problem Details (application/problem+json).\n\n", $custom);
    }
}
