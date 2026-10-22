<?php

declare(strict_types=1);

namespace Keel\Tests\Console;

use Keel\App\App;
use Keel\App\AppBuilder;
use Keel\Config\Env;
use Keel\Console\Command\DebugContainerCommand;
use Keel\Console\Command\MakeAuthenticatorCommand;
use Keel\Console\Command\MakeCheckCommand;
use Keel\Console\Command\MakeCommandBase;
use Keel\Console\Command\MakeCommandCommand;
use Keel\Console\Command\MakeControllerCommand;
use Keel\Console\Command\MakeListenerCommand;
use Keel\Console\Command\MakeMiddlewareCommand;
use Keel\Console\Command\MakePolicyCommand;
use Keel\Console\ConsoleFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

#[CoversClass(MakeCommandBase::class)]
#[CoversClass(MakeControllerCommand::class)]
#[CoversClass(MakeCommandCommand::class)]
#[CoversClass(MakeMiddlewareCommand::class)]
#[CoversClass(MakeListenerCommand::class)]
#[CoversClass(MakeCheckCommand::class)]
#[CoversClass(MakeAuthenticatorCommand::class)]
#[CoversClass(DebugContainerCommand::class)]
#[CoversClass(AppBuilder::class)]
#[CoversClass(MakePolicyCommand::class)]
final class MakeCommandsTest extends TestCase
{
    private string $project;

    private ApplicationTester $tester;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/make-' . uniqid();
        mkdir($this->project . '/src', 0o775, true);
        file_put_contents($this->project . '/composer.json', json_encode(['autoload' => ['psr-4' => ['Acme\\Shop\\' => 'src/']]]));
        $app = App::boot($this->project, new Env(['APP_ENV' => 'dev', 'APP_DEBUG' => '1']));
        $console = ConsoleFactory::create($app);
        $console->setAutoExit(false);
        $this->tester = new ApplicationTester($console);
    }

    public function testGeneratesClassesInTheProjectNamespace(): void
    {
        self::assertSame(0, $this->tester->run(['command' => 'keel:make:controller', 'name' => 'user', '--prefix' => 'api/users']));
        $controller = (string) file_get_contents($this->project . '/src/Controller/UserController.php');
        self::assertStringContainsString('namespace Acme\Shop\Controller;', $controller);
        self::assertStringContainsString("#[Prefix('/api/users')]", $controller);
        self::assertStringContainsString("#[Get('/{id:\\d+}', name: 'user.show')]", $controller);

        self::assertSame(0, $this->tester->run(['command' => 'keel:make:command', 'name' => 'app:import-users']));
        $command = (string) file_get_contents($this->project . '/src/Command/ImportUsersCommand.php');
        self::assertStringContainsString("#[AsCommand(name: 'app:import-users'", $command);

        self::assertSame(0, $this->tester->run(['command' => 'keel:make:middleware', 'name' => 'RateLimit']));
        self::assertFileExists($this->project . '/src/Middleware/RateLimitMiddleware.php');

        self::assertSame(0, $this->tester->run(['command' => 'keel:make:listener', 'name' => 'SendWelcome', 'event' => 'Acme\Shop\Event\UserRegistered']));
        $listener = (string) file_get_contents($this->project . '/src/Listener/SendWelcomeListener.php');
        self::assertStringContainsString('use Acme\Shop\Event\UserRegistered;', $listener);
        self::assertStringContainsString('public function __invoke(UserRegistered $event): void', $listener);

        foreach (glob($this->project . '/src/*/*.php') ?: [] as $file) {
            exec(sprintf('%s -l %s 2>&1', escapeshellarg(\PHP_BINARY), escapeshellarg($file)), $out, $code);
            self::assertSame(0, $code, implode("\n", $out));
        }
    }

    public function testRejectsNamesThatAreNotClassNamesAndQuotesInterpolatedStrings(): void
    {
        self::assertSame(1, $this->tester->run(['command' => 'keel:make:controller', 'name' => '123']));
        self::assertStringContainsString('class name', $this->tester->getDisplay());
        self::assertSame(1, $this->tester->run(['command' => 'keel:make:middleware', 'name' => '---']));
        self::assertSame(1, $this->tester->run(['command' => 'keel:make:listener', 'name' => 'Audit', 'event' => "App\\Event\\X'); system('id"]));
        self::assertStringContainsString('not a class name', $this->tester->getDisplay());
        self::assertSame(1, $this->tester->run(['command' => 'keel:make:command', 'name' => 'app:']));
        self::assertSame(1, $this->tester->run(['command' => 'keel:make:check', 'name' => 'Check']));
        self::assertStringContainsString('label empty', $this->tester->getDisplay());
        self::assertSame(1, $this->tester->run(['command' => 'keel:make:authenticator', 'name' => 'Authenticator', '--scheme' => true]));
        self::assertStringContainsString('empty', $this->tester->getDisplay());
        self::assertSame([], glob($this->project . '/src/*/*.php') ?: [], 'nothing was written');

        self::assertSame(0, $this->tester->run(['command' => 'keel:make:command', 'name' => "app:it's-x"]));
        $command = (string) file_get_contents($this->project . '/src/Command/ItSXCommand.php');
        self::assertStringContainsString("#[AsCommand(name: 'app:it\\'s-x'", $command);
        self::assertSame(0, $this->tester->run(['command' => 'keel:make:controller', 'name' => 'Odd', '--prefix' => "o'dd"]));
        $controller = (string) file_get_contents($this->project . '/src/Controller/OddController.php');
        self::assertStringContainsString("#[Prefix('/o\\'dd')]", $controller);

        foreach (glob($this->project . '/src/*/*.php') ?: [] as $file) {
            exec(sprintf('%s -l %s 2>&1', escapeshellarg(\PHP_BINARY), escapeshellarg($file)), $out, $code);
            self::assertSame(0, $code, implode("\n", $out));
        }
    }

    public function testRefusesToOverwriteWithoutForce(): void
    {
        $this->tester->run(['command' => 'keel:make:middleware', 'name' => 'Audit']);
        self::assertSame(1, $this->tester->run(['command' => 'keel:make:middleware', 'name' => 'Audit']));
        self::assertStringContainsString('use --force', $this->tester->getDisplay());
        self::assertSame(0, $this->tester->run(['command' => 'keel:make:middleware', 'name' => 'Audit', '--force' => true]));
    }

    public function testDebugContainerListsServicesAliasesAndInstances(): void
    {
        self::assertSame(0, $this->tester->run(['command' => 'keel:debug:container']));
        $display = $this->tester->getDisplay();

        self::assertStringContainsString('Keel\Http\Responder', $display);
        self::assertStringContainsString('Psr\Log\LoggerInterface', $display);
        self::assertStringContainsString('-> Keel\Log\StreamLogger', $display);
        self::assertStringContainsString('Keel\Config\AppConfig', $display);
        self::assertStringContainsString('instance', $display);

        self::assertSame(0, $this->tester->run(['command' => 'keel:debug:container', 'filter' => 'responder']));
        self::assertStringNotContainsString('LoggerInterface', $this->tester->getDisplay());
    }

    public function testGeneratesHealthChecksAndAuthenticators(): void
    {
        self::assertSame(0, $this->tester->run(['command' => 'keel:make:check', 'name' => 'redis-cluster']));
        $check = (string) file_get_contents($this->project . '/src/Health/RedisClusterCheck.php');
        self::assertStringContainsString('namespace Acme\Shop\Health;', $check);
        self::assertStringContainsString('#[Tag(HealthCheck::TAG)]', $check);
        self::assertStringContainsString("return 'redis_cluster';", $check);
        self::assertStringContainsString('final class RedisClusterCheck implements HealthCheck', $check);

        self::assertSame(0, $this->tester->run(['command' => 'keel:make:authenticator', 'name' => 'ApiTokens']));
        $resolver = (string) file_get_contents($this->project . '/src/Auth/ApiTokens.php');
        self::assertStringContainsString('#[Alias(PrincipalResolver::class)]', $resolver);
        self::assertStringContainsString('final class ApiTokens implements PrincipalResolver', $resolver);

        self::assertSame(0, $this->tester->run(['command' => 'keel:make:authenticator', 'name' => 'session']));
        self::assertFileExists($this->project . '/src/Auth/SessionResolver.php');

        self::assertSame(0, $this->tester->run(['command' => 'keel:make:authenticator', 'name' => 'Signature', '--scheme' => true]));
        $authenticator = (string) file_get_contents($this->project . '/src/Auth/SignatureAuthenticator.php');
        self::assertStringContainsString('final class SignatureAuthenticator implements Authenticator', $authenticator);
        self::assertStringContainsString("return 'signature';", $authenticator);
        self::assertStringContainsString("SecurityScheme::apiKey('signature', 'X-SignatureAuthenticator')", $authenticator);

        foreach ([$check, $resolver, $authenticator] as $source) {
            self::assertStringStartsWith("<?php\n\ndeclare(strict_types=1);", $source);
        }
    }

    public function testGeneratesPolicies(): void
    {
        self::assertSame(0, $this->tester->run(['command' => 'keel:make:policy', 'name' => 'blog-post']));
        $policy = (string) file_get_contents($this->project . '/src/Auth/BlogPostPolicy.php');
        self::assertStringContainsString('namespace Acme\Shop\Auth;', $policy);
        self::assertStringContainsString('#[Tag(Policy::TAG)]', $policy);
        self::assertStringContainsString("return ['blog_post.view', 'blog_post.update', 'blog_post.delete'];", $policy, 'default abilities derived from the resource');
        self::assertStringContainsString("'blog_post.update' => \$principal->hasRole('admin'),", $policy);

        self::assertSame(0, $this->tester->run(['command' => 'keel:make:policy', 'name' => 'CommentPolicy', 'abilities' => ['comment:edit', 'comment.hide']]));
        $comment = (string) file_get_contents($this->project . '/src/Auth/CommentPolicy.php');
        self::assertStringContainsString('final class CommentPolicy implements Policy', $comment, 'a trailing "Policy" is not doubled');
        self::assertStringContainsString("return ['comment:edit', 'comment.hide'];", $comment);
        exec(sprintf('%s -l %s 2>&1', escapeshellarg(\PHP_BINARY), escapeshellarg($this->project . '/src/Auth/CommentPolicy.php')), $out, $code);
        self::assertSame(0, $code, implode("\n", $out));

        self::assertSame(1, $this->tester->run(['command' => 'keel:make:policy', 'name' => 'Policy']));
        self::assertStringContainsString('"Policy" alone leaves no abilities to derive', $this->tester->getDisplay());
        self::assertSame(1, $this->tester->run(['command' => 'keel:make:policy', 'name' => '123']));
        self::assertStringContainsString('Resource name must be a class name', $this->tester->getDisplay());
        self::assertSame(1, $this->tester->run(['command' => 'keel:make:policy', 'name' => 'Order', 'abilities' => ["order.view'); system('id"]]));
        self::assertStringContainsString('must be letters, digits', $this->tester->getDisplay());
        self::assertFileDoesNotExist($this->project . '/src/Auth/OrderPolicy.php');
    }
}
