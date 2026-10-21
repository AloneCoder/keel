<?php

declare(strict_types=1);

namespace Keel\Tests\Cache;

use Keel\Cache\AbstractCache;
use Keel\Cache\ApcuCache;
use Keel\Cache\ArrayCache;
use Keel\Cache\CacheConfig;
use Keel\Cache\CacheProvider;
use Keel\Cache\FileCache;
use Keel\Cache\InvalidKeyException;
use Keel\Container\Container;
use Keel\Container\Definitions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

#[CoversClass(AbstractCache::class)]
#[CoversClass(ArrayCache::class)]
#[CoversClass(FileCache::class)]
#[CoversClass(ApcuCache::class)]
#[CoversClass(CacheConfig::class)]
#[CoversClass(CacheProvider::class)]
#[CoversClass(InvalidKeyException::class)]
final class CacheTest extends TestCase
{
    /**
     * @return iterable<string, array{0: \Closure(): CacheInterface}>
     */
    public static function drivers(): iterable
    {
        yield 'array' => [static fn(): CacheInterface => new ArrayCache()];
        yield 'file' => [static fn(): CacheInterface => new FileCache(sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/cache-' . uniqid(), gcDivisor: 1)];
    }

    /**
     * @param \Closure(): CacheInterface $factory
     */
    #[DataProvider('drivers')]
    public function testPsr16Contract(\Closure $factory): void
    {
        $cache = $factory();

        self::assertFalse($cache->has('a'));
        self::assertSame('dflt', $cache->get('a', 'dflt'));
        self::assertTrue($cache->set('a', ['x' => 1, 'o' => new \DateTimeImmutable('2026-01-01')]));
        self::assertTrue($cache->has('a'));
        self::assertEquals(['x' => 1, 'o' => new \DateTimeImmutable('2026-01-01')], $cache->get('a'));

        self::assertTrue($cache->set('gone', 1, -1), 'a non-positive TTL deletes');
        self::assertFalse($cache->has('gone'));
        self::assertTrue($cache->set('later', 1, new \DateInterval('PT1H')));
        self::assertTrue($cache->has('later'));
        self::assertTrue($cache->set('null', null));
        self::assertTrue($cache->has('null'), 'a stored null is a hit');

        self::assertTrue($cache->setMultiple(['m1' => 1, 'm2' => 2]));
        self::assertSame(['m1' => 1, 'm2' => 2, 'm3' => 0], $cache->getMultiple(['m1', 'm2', 'm3'], 0));
        self::assertTrue($cache->deleteMultiple(['m1', 'nope']));
        self::assertFalse($cache->has('m1'));
        self::assertTrue($cache->delete('m2'));
        self::assertTrue($cache->clear());
        self::assertFalse($cache->has('a'));

        $this->expectException(InvalidKeyException::class);
        $cache->get('bad{key}');
    }

    public function testExpiryIsEnforcedByTheClock(): void
    {
        $directory = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/cache-' . uniqid();
        $file = new FileCache($directory, gcDivisor: 0);
        $file->set('soon', 'v', 1);
        $array = new ArrayCache(1);
        $array->set('soon', 'v');

        $path = (string) (glob($directory . '/*.cache') ?: [''])[0];
        file_put_contents($path, serialize(['v', time() - 1]));
        self::assertNull($file->get('soon'), 'expired entry is dropped on read');
        self::assertFileDoesNotExist($path);

        $file->set('a', 1, 60);
        $file->set('b', 1, 60);
        foreach (glob($directory . '/*.cache') ?: [] as $entry) {
            file_put_contents($entry, serialize([1, time() - 10]));
        }
        $file->sweep();
        self::assertSame([], glob($directory . '/*.cache') ?: []);

        self::assertSame('v', $array->get('soon'), 'default TTL of one second still valid');
    }

    public function testProviderPicksTheDriver(): void
    {
        $definitions = new Definitions();
        $definitions->instance(CacheConfig::class, new CacheConfig('array', defaultTtl: 5));
        $definitions->load(new CacheProvider());
        $container = new Container($definitions);
        self::assertInstanceOf(ArrayCache::class, $container->get(CacheInterface::class));
        self::assertSame($container->get(CacheInterface::class), $container->get(CacheInterface::class));

        $directory = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/cache-' . uniqid();
        self::assertInstanceOf(FileCache::class, CacheProvider::create(new CacheConfig('file', $directory)));
        $auto = CacheProvider::create();
        self::assertInstanceOf(ApcuCache::available() ? ApcuCache::class : FileCache::class, $auto);

        $this->expectException(\InvalidArgumentException::class);
        CacheProvider::create(new CacheConfig('redis'));
    }

    public function testApcuCacheRefusesToStartWithoutTheExtension(): void
    {
        if (ApcuCache::available()) {
            self::markTestSkipped('APCu is loaded');
        }
        $this->expectException(\RuntimeException::class);
        new ApcuCache();
    }
}
