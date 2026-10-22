<?php

declare(strict_types=1);

namespace Keel\Tests\Cache;

use Keel\Auth\AuthConfig;
use Keel\Auth\BearerTokenAuthenticator;
use Keel\Auth\Identity;
use Keel\Auth\Principal;
use Keel\Auth\PrincipalResolver;
use Keel\Cache\ArrayCache;
use Keel\Cache\Attribute\OutputCache;
use Keel\Cache\OutputCacheConfig;
use Keel\Cache\OutputCacheMiddleware;
use Keel\Cache\OutputCacheStore;
use Keel\Container\Definitions;
use Keel\Kernel\Kernel;
use Keel\Testing\TestClient;
use Keel\Tests\Cache\Fixtures\CatalogController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

#[CoversClass(OutputCacheMiddleware::class)]
#[CoversClass(OutputCacheStore::class)]
#[CoversClass(OutputCacheConfig::class)]
#[CoversClass(OutputCache::class)]
final class OutputCacheTest extends TestCase
{
    private ArrayCache $cache;

    private TestClient $client;

    private OutputCacheStore $store;

    protected function setUp(): void
    {
        CatalogController::$runs = 0;
        $this->cache = new ArrayCache();
        $definitions = new Definitions();
        $definitions->instance(CacheInterface::class, $this->cache);
        $definitions->instance(AuthConfig::class, new AuthConfig([BearerTokenAuthenticator::class]));
        $definitions->instance(PrincipalResolver::class, new class implements PrincipalResolver {
            public function resolve(string $credential): ?Principal
            {
                return in_array($credential, ['ann', 'bob'], true) ? new Identity($credential) : null;
            }
        });
        $kernel = Kernel::build($definitions, [CatalogController::class]);
        $this->client = TestClient::forKernel($kernel);
        $this->store = $kernel->container->get(OutputCacheStore::class);
    }

    public function testHitsMissesAndVariants(): void
    {
        $this->client->get('/products/7')->assertOk()->assertJson(['run' => 1])->assertHeader('X-Cache', 'MISS')->assertHeaderMissing('Age');
        $this->client->get('/products/7')->assertOk()->assertJson(['run' => 1])->assertHeader('X-Cache', 'HIT')->assertHeader('Age', '0')
            ->assertHeader('Content-Type', 'application/json');
        $this->client->get('/products/7?utm=x')->assertJson(['run' => 1]); // only "lang" varies
        $this->client->get('/products/7?lang=de')->assertJson(['run' => 2]);
        $this->client->get('/products/7', ['Accept-Language' => 'fr'])->assertJson(['run' => 3]);
        $this->client->get('/products/8')->assertJson(['run' => 4]);
        $this->client->post('/products/7')->assertStatus(405);
        $this->client->get('/plain')->assertHeaderMissing('X-Cache');
    }

    public function testTagInvalidation(): void
    {
        $this->client->get('/products/7')->assertJson(['run' => 1]);
        $this->client->get('/products/8')->assertJson(['run' => 2]);

        $this->store->invalidateTags('product:7');
        $this->client->get('/products/7')->assertJson(['run' => 3])->assertHeader('X-Cache', 'MISS');
        $this->client->get('/products/8')->assertJson(['run' => 2])->assertHeader('X-Cache', 'HIT');

        $this->store->invalidateTags('products');
        $this->client->get('/products/7')->assertJson(['run' => 4]);
        $this->client->get('/products/8')->assertJson(['run' => 5]);
        $this->store->invalidateTags();
    }

    public function testEvictedTagVersionMakesEntriesStale(): void
    {
        $this->client->get('/products/7')->assertJson(['run' => 1]);
        $this->cache->clear(); // tags and pages gone alike
        $this->client->get('/products/7')->assertJson(['run' => 2]);

        // Only the tag versions vanish (an LRU eviction): the page must not be served again.
        $this->cache = new ArrayCache();
        $store = new OutputCacheStore($this->cache);
        $store->store('k', 200, [], 'body', ['a'], 60);
        self::assertNotNull($store->fetch('k'));
        $this->cache->delete('output.tag.' . hash('xxh128', 'a'));
        self::assertNull($store->fetch('k'));
    }

    public function testWhatIsNotCached(): void
    {
        $this->client->withHeader('Authorization', 'Bearer x')->get('/products/7')->assertJson(['run' => 1])->assertHeaderMissing('X-Cache');
        $this->client->withHeader('Authorization', 'Bearer x')->get('/products/7')->assertJson(['run' => 2]);

        $this->client->get('/cookie')->assertHeader('X-Cache', 'MISS');
        $this->client->get('/cookie')->assertHeader('X-Cache', 'MISS');
        $this->client->get('/private')->assertHeader('X-Cache', 'MISS');
        $this->client->get('/private')->assertHeader('X-Cache', 'MISS');
        self::assertSame(6, CatalogController::$runs);
    }

    public function testVaryByPrincipalCachesPerUser(): void
    {
        $ann = $this->client->withHeader('Authorization', 'Bearer ann');
        $bob = $this->client->withHeader('Authorization', 'Bearer bob');

        $ann->get('/me/dashboard')->assertJson(['user' => 'ann', 'run' => 1])->assertHeader('X-Cache', 'MISS');
        $ann->get('/me/dashboard')->assertJson(['user' => 'ann', 'run' => 1])->assertHeader('X-Cache', 'HIT');
        $bob->get('/me/dashboard')->assertJson(['user' => 'bob', 'run' => 2]);
        $this->client->get('/me/dashboard')->assertStatus(401);

        // Without #[Auth] in front there is no principal to key by: never one shared copy.
        $ann->get('/me/unguarded')->assertJson(['run' => 3])->assertHeaderMissing('X-Cache');
        $bob->get('/me/unguarded')->assertJson(['user' => 'Bearer bob', 'run' => 4])->assertHeaderMissing('X-Cache');
    }

    public function testConfigAndAttribute(): void
    {
        self::assertSame(
            ['ttl' => 5, 'tags' => ['a'], 'varyByQuery' => false, 'varyByHeaders' => ['accept'], 'varyByPrincipal' => true],
            new OutputCache(5, ['a'], false, ['Accept'], true)->value(),
        );
        self::assertSame([OutputCacheMiddleware::class], new OutputCache()->middleware());
        self::assertSame(OutputCache::KEY, new OutputCache()->key());

        $store = new OutputCacheStore(new ArrayCache(), new OutputCacheConfig(maxBodyBytes: 4, header: null));
        self::assertNull($store->config()->header);
        foreach ([
            static fn() => new OutputCache(ttl: 0),
            static fn() => new OutputCache(tags: ['']),
            static fn() => new OutputCacheConfig(prefix: 'a:b'),
            static fn() => new OutputCacheConfig(maxBodyBytes: 0),
        ] as $i => $case) {
            try {
                $case();
                self::fail('case ' . $i . ' accepted');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testLargeBodiesAreNotStored(): void
    {
        $definitions = new Definitions();
        $definitions->instance(CacheInterface::class, $this->cache);
        $definitions->instance(OutputCacheConfig::class, new OutputCacheConfig(maxBodyBytes: 5, header: 'X-Page'));
        $client = TestClient::forKernel(Kernel::build($definitions, [CatalogController::class]));

        $client->get('/products/7')->assertJson(['run' => 1])->assertHeader('X-Page', 'MISS');
        $client->get('/products/7')->assertJson(['run' => 2])->assertHeader('X-Page', 'MISS');
    }
}
