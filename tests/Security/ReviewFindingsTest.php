<?php

declare(strict_types=1);

namespace Keel\Tests\Security;

use Keel\Auth\AuthConfig;
use Keel\Auth\BearerTokenAuthenticator;
use Keel\Auth\PrincipalResolver;
use Keel\Cache\ArrayCache;
use Keel\Cache\CacheConfig;
use Keel\Cache\CacheProvider;
use Keel\Cache\FileCache;
use Keel\Container\Definitions;
use Keel\Http\CallableHandler;
use Keel\Http\ErrorResponseHooks;
use Keel\Http\Exception\UnprocessableContentException;
use Keel\Http\Middleware\SecurityHeadersMiddleware;
use Keel\Http\Responder;
use Keel\Idempotency\IdempotencyMiddleware;
use Keel\Kernel\ExceptionHandlerMiddleware;
use Keel\Kernel\Kernel;
use Keel\RateLimit\CounterStorage;
use Keel\RateLimit\InMemoryCounters;
use Keel\RateLimit\RateLimiter;
use Keel\Session\CookieSigner;
use Keel\Session\CookieStorage;
use Keel\Session\FileStorage;
use Keel\Session\RedisStorage;
use Keel\Session\SessionConfig;
use Keel\Session\SessionMiddleware;
use Keel\Storage\FlysystemStorage;
use Keel\Storage\LocalStorage;
use Keel\Storage\StorageException;
use Keel\Testing\TestClient;
use Keel\Tests\Auth\Fixtures\Tokens;
use Keel\Tests\Security\Fixtures\ReviewController;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * The findings of the 1.6.0 security review: a JSON body built to collide in PHP's hash tables,
 * idempotency keys scoped to a session id that changed per request, token guessing past the rate
 * limit, error responses skipping the after-logic of middleware, a cache directory swapped under a
 * worker, executable names on public disks, multipart bodies outside the idempotency fingerprint
 * and a signed non-id cookie answered 500.
 */
#[Group('security')]
final class ReviewFindingsTest extends TestCase
{
    private const string SECRET = 'session-secret-that-is-long-enough-32+';

    /** @var list<string> */
    private array $directories = [];

    protected function setUp(): void
    {
        ReviewController::$charges = 0;
        ReviewController::$sessionIds = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            self::remove($directory);
        }
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            chmod($path, 0o700);
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
                self::remove($path . '/' . $entry);
            }
            rmdir($path);

            return;
        }
        unlink($path);
    }

    /**
     * @param list<class-string<MiddlewareInterface>> $middleware
     */
    private function client(array $middleware = [], ?\Closure $define = null): TestClient
    {
        $definitions = new Definitions();
        $definitions->instance(CacheInterface::class, new ArrayCache());
        $definitions->set(Tokens::class)->alias(PrincipalResolver::class);
        $definitions->set(InMemoryCounters::class)->alias(CounterStorage::class);
        $definitions->instance(AuthConfig::class, new AuthConfig([BearerTokenAuthenticator::class]));
        $definitions->instance(SessionConfig::class, new SessionConfig(self::SECRET));
        if ($define !== null) {
            $define($definitions);
        }

        return TestClient::forKernel(Kernel::build($definitions, [ReviewController::class], $middleware));
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir() . '/keel-review-' . bin2hex(random_bytes(6));
        mkdir($directory, 0o700);
        $this->directories[] = $directory;

        return $directory;
    }

    private static function sessionCookie(string $header): string
    {
        if (preg_match('/keel_session=([^;]+)/', $header, $cookie) !== 1) {
            self::fail('expected a session cookie, got: ' . $header);
        }

        return $cookie[1];
    }

    public function testJsonKeysBuiltToCollideAreRefusedBeforeDecoding(): void
    {
        // All multiples of 2^18: one bucket in a table of 131,072, about 6 seconds to decode.
        $keys = [];
        for ($i = 0; $i < 131_072; $i++) {
            $keys[] = '"' . ($i << 18) . '":0';
        }
        $started = hrtime(true);
        $this->client()->request('POST', '/json', ['Content-Type' => 'application/json'], '{' . implode(',', $keys) . '}')->assertProblem(413);
        self::assertLessThan(1.0, (hrtime(true) - $started) / 1e9, 'refused without decoding');

        // Spreading them over many objects does not get around the budget.
        $object = '{' . implode(',', array_slice($keys, 0, 2_000)) . '}';
        $this->client()->request('POST', '/json', ['Content-Type' => 'application/json'], '[' . implode(',', array_fill(0, 26, $object)) . ']')->assertProblem(413);
    }

    public function testOrdinaryJsonStaysWithinTheKeyBudget(): void
    {
        $post = fn(string $json) => $this->client()->request('POST', '/json', ['Content-Type' => 'application/json'], $json);

        $map = [];
        for ($i = 0; $i < 9_000; $i++) {
            $map['key' . $i] = $i;
        }
        $post((string) json_encode($map))->assertOk()->assertJson(['count' => 9_000]);

        $row = ['id' => 1, 'name' => 'a', 'email' => 'a@example.test', 'active' => true];
        $post((string) json_encode(array_fill(0, 40_000, $row)))->assertOk()->assertJson(['count' => 40_000]);

        // Colons, braces and escaped quotes inside strings are no keys.
        $tricky = str_repeat('"}{:\\" : ', 20_000);
        $post((string) json_encode(['text' => $tricky, 'more' => 1]))->assertOk()->assertJson(['count' => 2]);
    }

    public function testIdempotencyKeyReplaysUnderTheDefaultCookieSession(): void
    {
        $client = $this->client([SessionMiddleware::class]);
        $user = $client->withCookie('keel_session', self::sessionCookie($client->post('/login')->header('Set-Cookie')));
        $headers = ['Idempotency-Key' => 'same', 'Content-Type' => 'application/json'];

        $user->post('/pay', '{}', $headers)->assertOk()->assertJson(['charge' => 1]);
        $user->post('/pay', '{}', $headers)->assertOk()->assertJson(['charge' => 1])->assertHeader('Idempotent-Replayed', 'true');
        self::assertSame(1, ReviewController::$charges);
    }

    public function testCookieSessionIdIsStableUntilRegeneratedAndSurvivesTheUpgrade(): void
    {
        $client = $this->client([SessionMiddleware::class]);
        $user = $client->withCookie('keel_session', self::sessionCookie($client->post('/login')->header('Set-Cookie')));
        $user->get('/whoami');
        $user->get('/whoami');
        self::assertSame(ReviewController::$sessionIds[0], ReviewController::$sessionIds[1]);

        $rotated = $client->withCookie('keel_session', self::sessionCookie($user->post('/rotate')->header('Set-Cookie')));
        $rotated->get('/whoami');
        self::assertNotSame(ReviewController::$sessionIds[0], ReviewController::$sessionIds[2]);

        // A cookie written before the id travelled with the data: stable as long as it is not rewritten.
        $legacy = new CookieSigner(self::SECRET)->sign((string) json_encode(['exp' => time() + 3_600, 'data' => ['uid' => 1]]));
        $old = $client->withCookie('keel_session', $legacy);
        $headers = ['Idempotency-Key' => 'legacy', 'Content-Type' => 'application/json'];
        $old->post('/pay', '{}', $headers)->assertJson(['charge' => 1]);
        $old->post('/pay', '{}', $headers)->assertJson(['charge' => 1])->assertHeader('Idempotent-Replayed', 'true');

        // A foreign, malformed id in a signed cookie is still just a label, never a storage key here.
        self::assertSame('abc', new CookieStorage(new CookieSigner(self::SECRET))->decode(new CookieStorage(new CookieSigner(self::SECRET))->encode([], 60, 'abc'))['id'] ?? null);
    }

    public function testGuessingTokensRunsIntoThePrincipalRateLimit(): void
    {
        $client = $this->client();
        for ($i = 0; $i < 3; $i++) {
            $client->get('/guarded', ['Authorization' => 'Bearer guess-' . $i])->assertProblem(401, 'Invalid or expired token');
        }
        $client->get('/guarded', ['Authorization' => 'Bearer guess-4'])->assertStatus(429)->assertHeader('RateLimit-Remaining', '0');

        // The right guess gets the same answer: nothing tells it from the wrong ones.
        $client->get('/guarded', ['Authorization' => 'Bearer user-token'])->assertStatus(429);
    }

    public function testValidTokensKeepTheirBucketBelowTheGuessLimitAndAnonymousRequestsLockNobodyOut(): void
    {
        $client = $this->client();
        $client->get('/guarded', ['Authorization' => 'Bearer guess'])->assertStatus(401);
        $client->get('/guarded', ['Authorization' => 'Bearer user-token'])->assertOk()->assertHeader('RateLimit-Remaining', '2');

        $anonymous = $this->client();
        for ($i = 0; $i < 4; $i++) {
            $anonymous->get('/guarded');
        }
        $anonymous->get('/guarded')->assertStatus(429);
        $anonymous->get('/guarded', ['Authorization' => 'Bearer user-token'])->assertOk();
    }

    public function testCountersCanBeReadWithoutCounting(): void
    {
        $counters = new InMemoryCounters();
        self::assertSame(0, $counters->current('k'));
        $counters->increment('k', 60);
        $counters->increment('k', 60);
        self::assertSame(2, $counters->current('k'), 'reading does not count');

        $limiter = new RateLimiter($counters);
        $before = $limiter->exhausted('w', 1, 60);
        self::assertNull($before);
        $limiter->hit('w', 1, 60);
        $after = $limiter->exhausted('w', 1, 60);
        self::assertNotNull($after);
        self::assertFalse($after->allowed);
    }

    public function testErrorResponsesCarryTheSecurityHeaders(): void
    {
        $client = $this->client([SecurityHeadersMiddleware::class]);

        $client->get('/nope')->assertStatus(404)->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY');
        $client->post('/logout-fails')->assertStatus(500)->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function testLogoutThatEndsInAnExceptionStillLogsOut(): void
    {
        $client = $this->client([SessionMiddleware::class]);
        $user = $client->withCookie('keel_session', self::sessionCookie($client->post('/login')->header('Set-Cookie')));

        $response = $user->post('/logout-fails')->assertStatus(500);
        self::assertStringContainsString('Max-Age=0', $response->header('Set-Cookie'));

        // Anything else a failed request wrote is dropped, as before.
        $user->post('/write-fails')->assertStatus(500)->assertHeaderMissing('Set-Cookie');
    }

    public function testLogoutThatEndsInAnExceptionRemovesTheStoredSession(): void
    {
        $storage = new FileStorage($this->directory());
        $id = str_repeat('a', 40);
        $storage->write($id, ['uid' => 1], 60);
        $client = $this->client([SessionMiddleware::class], static function (Definitions $definitions) use ($storage): void {
            $definitions->instance(SessionMiddleware::class, new SessionMiddleware(new SessionConfig(self::SECRET), $storage));
        });

        $client->withCookie('keel_session', new CookieSigner(self::SECRET)->sign($id))->post('/logout-fails')->assertStatus(500);
        self::assertNull($storage->read($id));
    }

    public function testAHookThatThrowsDoesNotCostTheErrorResponse(): void
    {
        $factory = new Psr17Factory();
        $handler = new CallableHandler(static function (ServerRequestInterface $request): ResponseInterface {
            ErrorResponseHooks::of($request)?->add(static fn(ResponseInterface $r): ResponseInterface => $r->withHeader('X-Outer', 'ran'));
            ErrorResponseHooks::of($request)?->add(static function (ResponseInterface $r): ResponseInterface {
                throw new \LogicException('hook');
            });

            throw new \RuntimeException('handler');
        });

        $response = new ExceptionHandlerMiddleware(new Responder($factory, $factory))->process(new ServerRequest('GET', '/'), $handler);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('ran', $response->getHeaderLine('X-Outer'));
    }

    public function testFileCacheNoticesADirectorySwappedUnderARunningWorker(): void
    {
        $root = $this->directory();
        $cache = new FileCache($root . '/cache');
        $cache->set('k', 'clean');
        self::assertSame('clean', $cache->get('k'));

        rename($root . '/cache', $root . '/cache.old');
        mkdir($root . '/cache');
        chmod($root . '/cache', 0o777);
        file_put_contents($root . '/cache/' . hash('xxh128', 'k') . '.cache', serialize([new \ArrayObject(['planted']), null]));

        $this->expectException(\RuntimeException::class);
        $cache->get('k');
    }

    public function testCacheAndSessionDirectoriesInAWorldWritableParentAreRefused(): void
    {
        $root = $this->directory();
        chmod($root, 0o777);

        try {
            new FileCache($root . '/cache')->set('k', 'v');
            self::fail('the cache accepted a directory anyone can replace');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('rename', $e->getMessage());
        }

        try {
            new FileStorage($root . '/sessions')->write(str_repeat('a', 40), [], 60);
            self::fail('the session storage accepted a directory anyone can replace');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('rename', $e->getMessage());
        }

        // The sticky bit of a shared temp dir stops others from renaming what is not theirs.
        chmod($root, 0o1777);
        $cache = new FileCache($root . '/cache');
        self::assertTrue($cache->set('k', 'v'));
        self::assertSame('v', $cache->get('k'));
    }

    public function testDefaultFileCacheDirectorySitsDirectlyInTheTempDir(): void
    {
        $cache = CacheProvider::create(new CacheConfig(driver: 'file', prefix: 'review-' . bin2hex(random_bytes(4))));
        self::assertInstanceOf(FileCache::class, $cache);
        $directory = new \ReflectionProperty(FileCache::class, 'directory')->getValue($cache);
        self::assertIsString($directory);
        self::assertSame(sys_get_temp_dir(), dirname($directory));
    }

    public function testPublicDisksRefuseExecutableNames(): void
    {
        $flysystem = new FlysystemStorage(new Filesystem(new LocalFilesystemAdapter($this->directory())), 'https://files.test');
        foreach (['shell.php', 'a/shell.PHP7', 'x.php.png', 'page.phtm', 'page.shtml', 'run.cgi', '.htaccess', '.user.ini'] as $name) {
            try {
                $flysystem->write($name, 'x');
                self::fail('flysystem wrote ' . $name);
            } catch (StorageException $e) {
                self::assertStringContainsString('public disk', $e->getMessage());
            }
        }
        $flysystem->write('photo.png', 'x');
        try {
            $flysystem->move('photo.png', 'photo.php');
            self::fail('flysystem moved a file to an executable name');
        } catch (StorageException $e) {
            self::assertStringContainsString('public disk', $e->getMessage());
        }

        $local = new LocalStorage($this->directory(), 'https://files.test');
        foreach (['page.phtm', 'page.shtml', 'run.cgi'] as $name) {
            try {
                $local->write($name, 'x');
                self::fail('local wrote ' . $name);
            } catch (StorageException $e) {
                self::assertStringContainsString('public disk', $e->getMessage());
            }
        }

        $private = new FlysystemStorage(new Filesystem(new LocalFilesystemAdapter($this->directory())));
        $private->write('backup.php', 'x');
        self::assertTrue($private->exists('backup.php'), 'a disk without a URL serves nothing');
    }

    public function testIdempotencyFingerprintCoversFormFieldsAndUploads(): void
    {
        $runs = 0;
        $handler = new CallableHandler(static function () use (&$runs): ResponseInterface {
            $runs++;

            return new Response(200, [], 'stored');
        });
        $middleware = new IdempotencyMiddleware(new ArrayCache());
        $request = static fn(string $key): ServerRequest => new ServerRequest('POST', '/pay', ['Idempotency-Key' => $key, 'Authorization' => 'x']);
        $upload = static function (string $content): UploadedFile {
            $stream = new Psr17Factory()->createStream($content);

            return new UploadedFile($stream, strlen($content), \UPLOAD_ERR_OK, 'a.txt', 'text/plain');
        };

        $middleware->process($request('fields')->withParsedBody(['amount' => '1']), $handler);
        self::assertSame('true', $middleware->process($request('fields')->withParsedBody(['amount' => '1']), $handler)->getHeaderLine('Idempotent-Replayed'));
        try {
            $middleware->process($request('fields')->withParsedBody(['amount' => '999']), $handler);
            self::fail('another form under the same key was replayed');
        } catch (UnprocessableContentException) {
        }

        $middleware->process($request('files')->withUploadedFiles(['doc' => $upload('one')]), $handler);
        self::assertSame('true', $middleware->process($request('files')->withUploadedFiles(['doc' => $upload('one')]), $handler)->getHeaderLine('Idempotent-Replayed'));
        try {
            $middleware->process($request('files')->withUploadedFiles(['doc' => $upload('two')]), $handler);
            self::fail('another upload under the same key was replayed');
        } catch (UnprocessableContentException) {
        }

        self::assertSame(2, $runs);
    }

    public function testASignedCookieThatIsNoSessionIdStartsANewRedisSession(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('needs ext-redis');
        }
        $storage = new RedisStorage(new \Redis());
        self::assertNull($storage->read('not-an-id'));
        self::assertNull($storage->expiresAt('not-an-id'));
        $storage->destroy('not-an-id');

        $client = $this->client([SessionMiddleware::class], static function (Definitions $definitions) use ($storage): void {
            $definitions->instance(SessionMiddleware::class, new SessionMiddleware(new SessionConfig(self::SECRET), $storage));
        });
        $old = new CookieSigner(self::SECRET)->sign('{"exp":9999999999,"data":{"uid":1}}');
        $client->withCookie('keel_session', $old)->get('/whoami')->assertOk();
    }
}
