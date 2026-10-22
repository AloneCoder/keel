<?php

declare(strict_types=1);

namespace Keel\Tests\Cache;

use Keel\Cache\CacheConfig;
use Keel\Cache\CacheProvider;
use Keel\Cache\InvalidKeyException;
use Keel\Cache\MemcachedCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * MemcachedCache runs against a real server: set KEEL_MEMCACHED_HOST (127.0.0.1:11211) with
 * ext-memcached loaded; otherwise the test is skipped. clear() flushes the whole instance, so
 * point it at a disposable server.
 */
#[CoversClass(MemcachedCache::class)]
final class MemcachedCacheTest extends TestCase
{
    public function testEntriesAgainstAServer(): void
    {
        $server = getenv('KEEL_MEMCACHED_HOST');
        if (!class_exists(\Memcached::class) || !is_string($server) || $server === '') {
            self::markTestSkipped('needs ext-memcached and KEEL_MEMCACHED_HOST');
        }
        $prefix = 'keel-test:' . getmypid() . ':';
        $cache = CacheProvider::create(new CacheConfig('memcached', prefix: $prefix, defaultTtl: 60, servers: [$server]));
        self::assertInstanceOf(MemcachedCache::class, $cache);

        [$host, $port] = array_pad(explode(':', $server, 2), 2, '11211');
        $raw = new \Memcached();
        $raw->addServer($host, (int) $port);

        self::assertFalse($cache->has('a'));
        self::assertSame('dflt', $cache->get('a', 'dflt'));
        self::assertTrue($cache->set('a', ['x' => 1, 'o' => new \DateTimeImmutable('2026-01-01')]));
        self::assertTrue($cache->has('a'));
        self::assertEquals(['x' => 1, 'o' => new \DateTimeImmutable('2026-01-01')], $cache->get('a'));
        self::assertEquals(['x' => 1, 'o' => new \DateTimeImmutable('2026-01-01')], $raw->get($prefix . 'cache:a'), 'entries live under prefix + "cache:"');

        self::assertTrue($cache->set('null', null));
        self::assertTrue($cache->has('null'), 'a stored null is a hit');
        self::assertNull($cache->get('null', 'miss'));
        self::assertTrue($cache->set('false', false));
        self::assertFalse($cache->get('false', 'miss'), 'a stored false is a hit');

        self::assertTrue($cache->set('gone', 1, -1), 'a non-positive TTL deletes');
        self::assertFalse($cache->has('gone'));
        self::assertTrue($cache->set('later', 1, new \DateInterval('PT1H')));
        self::assertTrue($cache->has('later'));
        self::assertTrue($cache->set('month', 1, 2592001), 'TTLs over 30 days go to Memcached as a timestamp');
        self::assertTrue($cache->has('month'));
        self::assertTrue($cache->set('forever', 1, null));
        self::assertTrue($cache->has('forever'));

        $long = str_repeat('k', 251);
        self::assertTrue($cache->set($long, 'hashed'));
        self::assertSame('hashed', $cache->get($long));
        self::assertSame('hashed', $raw->get($prefix . 'cache:' . hash('sha256', $long)), 'keys over 250 bytes are hashed');

        self::assertTrue($cache->setMultiple(['m1' => 1, 'm2' => 2]));
        self::assertSame(['m1' => 1, 'm2' => 2, 'm3' => 0], $cache->getMultiple(['m1', 'm2', 'm3'], 0));
        self::assertTrue($cache->deleteMultiple(['m1', 'nope']), 'deleting a missing key is not a failure');
        self::assertFalse($cache->has('m1'));
        self::assertTrue($cache->set('42', 'answer'), 'numeric-string keys are valid PSR-16 keys');
        self::assertSame([42 => 'answer'], $cache->getMultiple(['42']));
        self::assertTrue($cache->delete('42'));
        self::assertFalse($cache->has('42'));

        $calls = 0;
        $compute = static function () use (&$calls): string {
            return 'v' . ++$calls;
        };
        self::assertSame('v1', $cache->remember('r', 60, $compute));
        self::assertSame('v1', $cache->remember('r', 60, $compute), 'remember() computes once');
        self::assertSame(1, $calls);

        self::assertTrue($cache->clear());
        self::assertFalse($cache->has('a'));
        self::assertFalse($cache->has('forever'));
        self::assertFalse($raw->get($prefix . 'cache:later'));
        self::assertSame(\Memcached::RES_NOTFOUND, $raw->getResultCode(), 'clear() flushes the instance');

        $this->expectException(InvalidKeyException::class);
        $cache->get('bad{key}');
    }
}
