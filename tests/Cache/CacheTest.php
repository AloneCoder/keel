<?php

declare(strict_types=1);

namespace Keel\Tests\Cache;

use Keel\Cache\AbstractCache;
use Keel\Cache\ApcuCache;
use Keel\Cache\ArrayCache;
use Keel\Cache\Cache;
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
        self::assertTrue($cache->setMultiple(['42' => 'answer']), 'numeric-string keys (ints to PHP) are valid PSR-16 keys');
        self::assertSame('answer', $cache->get('42'));
        self::assertSame([42 => 'answer'], $cache->getMultiple(['42']));
        self::assertTrue($cache->deleteMultiple(['42']));
        self::assertFalse($cache->has('42'));
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

    public function testFileCacheRefusesDirectoriesOthersCanWriteTo(): void
    {
        $base = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/cache-' . uniqid();
        mkdir($base, 0o700, true);

        $cache = new FileCache($base . '/fresh');
        self::assertTrue($cache->set('k', 'v'));
        self::assertSame(0o700, fileperms($base . '/fresh') & 0o777, 'a created directory is private to the user');

        $open = $base . '/open';
        mkdir($open, 0o777);
        chmod($open, 0o777);
        if ((fileperms($open) & 0o002) === 0) {
            self::markTestSkipped('filesystem does not keep world-writable mode bits');
        }
        try {
            new FileCache($open)->set('k', 'v');
            self::fail('a world-writable directory is refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('writable by everyone', $e->getMessage());
        }
        $planted = new FileCache($open);
        file_put_contents($open . '/' . hash('xxh128', 'k') . '.cache', serialize(['planted', null]));
        try {
            $planted->get('k');
            self::fail('reads from a world-writable directory are refused too');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('writable by everyone', $e->getMessage());
        }

        symlink($base . '/fresh', $base . '/link');
        try {
            new FileCache($base . '/link')->set('k', 'v');
            self::fail('a symlinked directory is refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('symlink', $e->getMessage());
        }
    }

    public function testFileCacheSweepsStaleTemporaryFiles(): void
    {
        $directory = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/cache-' . uniqid();
        $cache = new FileCache($directory, gcDivisor: 0);
        $cache->set('a', 1);

        file_put_contents($directory . '/stale.tmp', 'x');
        touch($directory . '/stale.tmp', time() - 120);
        file_put_contents($directory . '/recent.tmp', 'x');

        $cache->sweep();
        self::assertFileDoesNotExist($directory . '/stale.tmp', 'leftovers of a crashed write are removed');
        self::assertFileExists($directory . '/recent.tmp', 'a write in progress is left alone');
        self::assertSame(1, $cache->get('a'));

        self::assertTrue($cache->clear());
        self::assertSame([], glob($directory . '/*') ?: []);
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

        self::assertInstanceOf(Cache::class, $auto);
        self::assertSame($container->get(CacheInterface::class), $container->get(Cache::class), 'Keel\\Cache\\Cache is an alias of the PSR interface');

        try {
            CacheProvider::create(new CacheConfig('redis'));
            self::fail('redis without a connection accepted');
        } catch (\LogicException $e) {
            self::assertStringContainsString('RedisProvider', $e->getMessage());
        }
        if (!class_exists(\Memcached::class)) {
            try {
                CacheProvider::create(new CacheConfig('memcached'));
                self::fail('memcached without the extension accepted');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('ext-memcached', $e->getMessage());
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        CacheProvider::create(new CacheConfig('memory'));
    }

    public function testRememberComputesOnceAndKeepsNull(): void
    {
        $cache = new ArrayCache();
        $calls = 0;
        $compute = static function () use (&$calls): ?string {
            $calls++;

            return $calls === 1 ? null : 'later';
        };

        self::assertNull($cache->remember('k', 60, $compute));
        self::assertNull($cache->remember('k', 60, $compute), 'a stored null is a hit');
        self::assertSame(1, $calls);
        self::assertSame('v', $cache->remember('other', null, static fn(): string => 'v'));
        self::assertSame('v', $cache->get('other'));
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
