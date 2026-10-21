<?php

declare(strict_types=1);

namespace Keel\Tests\Container;

use Keel\Container\Compiled\AbstractCompiledContainer;
use Keel\Container\Compiler;
use Keel\Container\ContainerInterface;
use Keel\Container\Definitions;
use Keel\Container\Exception\ContainerException;
use Keel\Container\Exception\NotFoundException;
use Keel\Tests\Container\Fixtures\Connection;
use Keel\Tests\Container\Fixtures\ConnectionFactory;
use Keel\Tests\Container\Fixtures\HttpConfig;
use Keel\Tests\Container\Fixtures\Logger;
use Keel\Tests\Container\Fixtures\Mailer;
use Keel\Tests\Container\Fixtures\Newsletter;
use Keel\Tests\Container\Fixtures\PrivateCtor;
use Keel\Tests\Container\Fixtures\Unresolvable;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Compiler::class)]
#[CoversClass(AbstractCompiledContainer::class)]
final class CompiledContainerTest extends ContainerBehaviourTestCase
{
    private static int $counter = 0;

    protected function createContainer(Definitions $definitions, bool $autowire = true): ContainerInterface
    {
        $fqcn = self::load($definitions, 'KeelCompiledContainer' . self::$counter++);

        return new $fqcn($definitions->instances(), $autowire);
    }

    /**
     * Compiles the definitions into a temporary file, loads it and returns the class name.
     *
     * @return class-string<AbstractCompiledContainer>
     */
    private static function load(Definitions $definitions, string $className): string
    {
        $path = self::directory() . '/' . $className . '.php';
        new Compiler($definitions)->write($path, $className, 'Keel\Tests\Generated');
        self::assertFileExists($path);
        require $path;

        $fqcn = sprintf('Keel\\Tests\\Generated\\%s', $className);
        if (!is_a($fqcn, AbstractCompiledContainer::class, true)) {
            self::fail('Generated class is missing or has a wrong base: ' . $fqcn);
        }

        return $fqcn;
    }

    public function testCompilesClassesReachableThroughConstructors(): void
    {
        $definitions = new Definitions();
        $definitions->set(Newsletter::class);

        $source = new Compiler($definitions)->compile();

        self::assertStringContainsString('/** ' . Newsletter::class . ' */', $source);
        self::assertStringContainsString('/** ' . Mailer::class . ' */', $source);
        self::assertStringContainsString('/** ' . Logger::class . ' */', $source);
        self::assertStringNotContainsString('ReflectionClass', $source, 'no lazy services, no reflection');
    }

    public function testUnreachableClassIsAutowiredAtRuntimeUnlessDisabled(): void
    {
        $container = $this->createContainer(new Definitions(), autowire: false);

        $this->expectException(NotFoundException::class);
        $container->get(Logger::class);
    }

    public function testMissingRequiredInstanceIsReportedAtConstruction(): void
    {
        $definitions = new Definitions();
        $definitions->instance(HttpConfig::class, new HttpConfig());
        $fqcn = self::load($definitions, 'KeelCompiledContainerMissing');

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Compiled container requires instances for: ' . HttpConfig::class);
        new $fqcn([]);
    }

    public function testAutowiringIsOnByDefault(): void
    {
        $fqcn = self::load(new Definitions(), 'KeelCompiledContainerDefaults');
        $container = new $fqcn();

        self::assertTrue($container->has(Logger::class));
        self::assertSame($container->get(Logger::class), $container->get(Logger::class), 'autowired classes are shared');
    }

    public function testClosureFactoryCannotBeCompiled(): void
    {
        $definitions = new Definitions();
        $definitions->set(Logger::class)->factory(static fn(): Logger => new Logger());

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Closure factory');
        new Compiler($definitions)->compile();
    }

    public function testObjectBoundFactoryCannotBeCompiled(): void
    {
        $definitions = new Definitions();
        $definitions->set(Connection::class)->factory([new ConnectionFactory(new Logger()), 'open']);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('bound to an object');
        new Compiler($definitions)->compile();
    }

    public function testObjectArgumentCannotBeCompiled(): void
    {
        $definitions = new Definitions();
        $definitions->set(Mailer::class)->argument('logger', new Logger());

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('cannot be compiled');
        new Compiler($definitions)->compile();
    }

    public function testUnresolvableRegisteredServiceFailsAtCompileTime(): void
    {
        $definitions = new Definitions();
        $definitions->set(Unresolvable::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot resolve parameter $name');
        new Compiler($definitions)->compile();
    }

    public function testPrivateConstructorIsRejected(): void
    {
        $definitions = new Definitions();
        $definitions->set(PrivateCtor::class)->lazy();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('not instantiable');
        new Compiler($definitions)->compile();
    }

    public function testGeneratedSourcePassesLint(): void
    {
        $definitions = new Definitions();
        $definitions->parameter('db.dsn', 'x');
        $definitions->parameter('nested', ['a' => [1, 2], 'b' => null, 'c' => true]);
        $definitions->set(Newsletter::class);
        $definitions->set(Connection::class)->factory(ConnectionFactory::class . '::create')->lazy();
        $path = self::directory() . '/Lint.php';
        new Compiler($definitions)->write($path, 'Lint', 'Keel\Tests\Generated');

        exec(sprintf('%s -l %s 2>&1', escapeshellarg(\PHP_BINARY), escapeshellarg($path)), $output, $code);

        self::assertSame(0, $code, implode("\n", $output));
    }

    private static function directory(): string
    {
        $directory = sys_get_temp_dir() . '/keel-tests/' . getmypid();
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        return $directory;
    }
}
