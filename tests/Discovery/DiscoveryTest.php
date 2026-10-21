<?php

declare(strict_types=1);

namespace Keel\Tests\Discovery;

use Keel\Discovery\ClassScanner;
use Keel\Discovery\CommandDiscovery;
use Keel\Discovery\Discoverer;
use Keel\Discovery\ListenerDiscovery;
use Keel\Discovery\RouteDiscovery;
use Keel\Discovery\ServiceDiscovery;
use Keel\Tests\App\Fixtures\Project\Src\Circle;
use Keel\Tests\App\Fixtures\Project\Src\Color;
use Keel\Tests\App\Fixtures\Project\Src\GreetController;
use Keel\Tests\App\Fixtures\Project\Src\Greeted;
use Keel\Tests\App\Fixtures\Project\Src\GreetingLog;
use Keel\Tests\App\Fixtures\Project\Src\HelloCommand;
use Keel\Tests\App\Fixtures\Project\Src\Shape;
use Keel\Tests\App\Fixtures\Project\Src\Square;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassScanner::class)]
#[CoversClass(Discoverer::class)]
#[CoversClass(RouteDiscovery::class)]
#[CoversClass(ServiceDiscovery::class)]
#[CoversClass(ListenerDiscovery::class)]
#[CoversClass(CommandDiscovery::class)]
final class DiscoveryTest extends TestCase
{
    private const string SRC = __DIR__ . '/../App/Fixtures/Project/Src';

    public function testScannerReadsDeclarationsWithoutLoadingFiles(): void
    {
        $source = <<<'PHP'
            <?php
            namespace Acme\Shop;
            use Foo\Bar;
            interface Priced {}
            enum Size: string { case S = 's'; }
            abstract class Base { public function x() { return new class {}; } public function y() { return Bar::class; } }
            final class Product extends Base implements Priced {}
            trait Helper {}
            PHP;

        self::assertSame(
            ['Acme\Shop\Priced', 'Acme\Shop\Size', 'Acme\Shop\Base', 'Acme\Shop\Product', 'Acme\Shop\Helper'],
            ClassScanner::declaredIn($source),
        );
        self::assertSame(['Plain'], ClassScanner::declaredIn('<?php class Plain {}'));
    }

    public function testScanFindsOnlyFilesWithAttributes(): void
    {
        $scan = new ClassScanner()->scan([self::SRC, '/nonexistent']);

        self::assertContains(GreetController::class, $scan['classes']);
        self::assertContains(Circle::class, $scan['classes']);
        self::assertNotContains(Shape::class, $scan['classes'], 'files without attributes are skipped');
        self::assertNotContains('Keel\Tests\App\Fixtures\Project\Src\NotDiscovered', $scan['classes']);
        self::assertGreaterThan(0, $scan['mtime']);
        self::assertSame(count(glob(self::SRC . '/*.php') ?: []), $scan['files']);
    }

    public function testDiscoveriesCollectControllersServicesListenersAndCommands(): void
    {
        $discoverer = self::discoverer(null);

        self::assertFalse($discoverer->run());
        self::assertSame([GreetController::class], $discoverer->get(RouteDiscovery::class)->controllers());
        self::assertSame([Circle::class, GreetingLog::class, Square::class], $discoverer->get(ServiceDiscovery::class)->services());
        self::assertSame([[GreetingLog::class, 'onGreeted', Greeted::class, 0]], $discoverer->get(ListenerDiscovery::class)->listeners());
        self::assertSame(['demo:hello' => HelloCommand::class], $discoverer->get(CommandDiscovery::class)->commands());
        self::assertTrue(enum_exists(Color::class));
    }

    public function testCacheIsReusedWhileFilesAreUnchangedAndSkippedInProduction(): void
    {
        $cache = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/discovery-' . uniqid() . '.php';

        self::assertFalse(self::discoverer($cache)->run(), 'first run scans');
        self::assertFileExists($cache);

        $second = self::discoverer($cache);
        self::assertTrue($second->run(), 'second run restores from cache');
        self::assertSame([GreetController::class], $second->get(RouteDiscovery::class)->controllers());
        self::assertSame(['demo:hello' => HelloCommand::class], $second->get(CommandDiscovery::class)->commands());

        // Tamper with the cache: production trusts it, development notices the stale metadata.
        $data = require $cache;
        self::assertIsArray($data);
        $data['mtime'] = 1;
        file_put_contents($cache, '<?php return ' . var_export($data, true) . ';');

        self::assertFalse(self::discoverer($cache)->run(), 'debug mode rescans when metadata differs');
        self::assertTrue(self::discoverer($cache, false)->run(), 'production uses the cache blindly');

        $missing = self::discoverer($cache);
        $missing->clearCache();
        self::assertFileDoesNotExist($cache);
    }

    public function testUnknownDiscoveryIsReported(): void
    {
        $this->expectException(\LogicException::class);
        new Discoverer([self::SRC])->get(RouteDiscovery::class);
    }

    private static function discoverer(?string $cache, bool $check = true): Discoverer
    {
        return new Discoverer([self::SRC], $cache, $check)
            ->add(new RouteDiscovery())
            ->add(new ServiceDiscovery())
            ->add(new ListenerDiscovery())
            ->add(new CommandDiscovery());
    }
}
