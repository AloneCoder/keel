<?php

declare(strict_types=1);

namespace Keel\Tests\Redis;

use Keel\Cache\RedisCache;
use Keel\Container\Definitions;
use Keel\Metrics\RedisStorage as RedisMetrics;
use Keel\RateLimit\RedisCounters;
use Keel\Redis\RedisCheck;
use Keel\Redis\RedisConfig;
use Keel\Redis\RedisProvider;
use Keel\Session\RedisStorage as RedisSessions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The Redis-backed classes run against a real server: set KEEL_REDIS_URL (redis://127.0.0.1:6379/15)
 * with ext-redis loaded; otherwise only the configuration is tested.
 */
#[CoversClass(RedisConfig::class)]
#[CoversClass(RedisProvider::class)]
#[CoversClass(RedisCheck::class)]
#[CoversClass(RedisCache::class)]
#[CoversClass(RedisSessions::class)]
#[CoversClass(RedisCounters::class)]
#[CoversClass(RedisMetrics::class)]
final class RedisTest extends TestCase
{
    public function testConfigFromUrlAndValidation(): void
    {
        $config = RedisConfig::fromUrl('redis://:s%3Acret@cache.internal:6380/3', 'app:', false, ['sessions', 'metrics', 'sessions']);
        self::assertSame('cache.internal', $config->host);
        self::assertSame(6380, $config->port);
        self::assertSame('s:cret', $config->auth);
        self::assertSame(3, $config->database);
        self::assertSame('app:', $config->prefix);
        self::assertFalse($config->persistent);
        self::assertSame(['sessions', 'metrics'], $config->use);

        $tls = RedisConfig::fromUrl('rediss://user@host');
        self::assertSame('tls://host', $tls->host);
        self::assertSame('user', $tls->auth);
        self::assertSame(0, $tls->database);
        self::assertNull(new RedisConfig()->auth);

        try {
            new RedisConfig(use: ['queues']);
            self::fail('unknown use accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('sessions, counters, metrics', $e->getMessage());
        }
        try {
            new RedisConfig(port: 0);
            self::fail('port accepted');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        RedisConfig::fromUrl('mysql://host');
    }

    public function testProviderNeedsTheExtension(): void
    {
        if (class_exists(\Redis::class)) {
            self::markTestSkipped('ext-redis is loaded');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ext-redis');
        new RedisProvider()->register(new Definitions());
    }

    public function testStoragesAgainstAServer(): void
    {
        $url = getenv('KEEL_REDIS_URL');
        if (!class_exists(\Redis::class) || !is_string($url) || $url === '') {
            self::markTestSkipped('needs ext-redis and KEEL_REDIS_URL');
        }
        $config = RedisConfig::fromUrl($url, 'keel-test:' . getmypid() . ':', false, ['sessions', 'counters', 'metrics']);
        $redis = RedisProvider::connect($config);

        $cache = new RedisCache($redis, $config->prefix . 'cache:', 60);
        self::assertTrue($cache->set('a', ['x' => 1]));
        self::assertSame(['x' => 1], $cache->get('a'));
        self::assertTrue($cache->has('a'));
        self::assertSame(['a' => ['x' => 1], 'b' => 'd'], $cache->getMultiple(['a', 'b'], 'd'));
        self::assertTrue($cache->set('b', false));
        self::assertFalse($cache->get('b', 'miss'), 'a stored false is a hit');
        self::assertTrue($cache->set('a', 1, 0), 'a non-positive ttl deletes');
        self::assertFalse($cache->has('a'));
        self::assertSame('v', $cache->remember('r', 60, static fn(): string => 'v'));
        self::assertTrue($cache->clear());
        self::assertFalse($cache->has('r'));

        $sessions = RedisProvider::sessions($redis, $config);
        $id = str_repeat('ab', 20);
        $sessions->write($id, ['user' => 1], 120);
        self::assertSame(['user' => 1], $sessions->read($id));
        $expires = $sessions->expiresAt($id);
        self::assertNotNull($expires);
        self::assertEqualsWithDelta(time() + 120, $expires, 2);
        $sessions->destroy($id);
        self::assertNull($sessions->read($id));
        self::assertNull($sessions->expiresAt($id));

        $counters = RedisProvider::counters($redis, $config);
        self::assertSame(1, $counters->increment('k', 60));
        self::assertSame(2, $counters->increment('k', 60));
        $redis->del($config->prefix . 'rl:k');

        $metrics = RedisProvider::metrics($redis, $config);
        $metrics->add('hits', 2);
        $metrics->add('hits', 3);
        $metrics->set('gauge', 7);
        self::assertSame(['hits' => 5, 'gauge' => 7], $metrics->all());
        $redis->del($config->prefix . 'metrics');

        self::assertSame('up', RedisProvider::check(new \Keel\Container\Container(new Definitions()))->name() === 'redis' ? new RedisCheck($redis)->check()->status->value : 'wrong');
    }
}
