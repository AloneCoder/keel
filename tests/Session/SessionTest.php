<?php

declare(strict_types=1);

namespace Keel\Tests\Session;

use Keel\Http\CallableHandler;
use Keel\Session\ArrayStorage;
use Keel\Session\CookieSigner;
use Keel\Session\CookieStorage;
use Keel\Session\FileStorage;
use Keel\Session\Session;
use Keel\Session\SessionConfig;
use Keel\Session\SessionMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(SessionMiddleware::class)]
#[CoversClass(Session::class)]
#[CoversClass(SessionConfig::class)]
#[CoversClass(CookieSigner::class)]
#[CoversClass(CookieStorage::class)]
#[CoversClass(FileStorage::class)]
#[CoversClass(ArrayStorage::class)]
final class SessionTest extends TestCase
{
    private const string SECRET = 'a-secret-that-is-long-enough-for-hmac-signing!';

    /**
     * @param \Closure(Session): void $action
     */
    private static function handler(\Closure $action): CallableHandler
    {
        return new CallableHandler(static function (ServerRequestInterface $request) use ($action): ResponseInterface {
            $session = $request->getAttribute(Session::class);
            self::assertInstanceOf(Session::class, $session);
            $action($session);

            return new Psr17Factory()->createResponse();
        });
    }

    private static function cookieOf(ResponseInterface $response): string
    {
        $header = $response->getHeaderLine('Set-Cookie');
        self::assertNotSame('', $header, 'Set-Cookie expected');

        return explode(';', substr($header, strlen('keel_session=')), 2)[0];
    }

    public function testCookieStorageRoundTripTamperingAndExpiry(): void
    {
        $middleware = new SessionMiddleware(new SessionConfig(self::SECRET, ttl: 60));

        $first = $middleware->process(new ServerRequest('GET', 'https://app.test/'), self::handler(static function (Session $s): void {
            self::assertTrue($s->isNew());
            $s->set('user', 7);
            $s->set('cart', ['a' => 1]);
        }));
        $cookie = self::cookieOf($first);
        self::assertStringContainsString('HttpOnly', $first->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('Secure', $first->getHeaderLine('Set-Cookie'), 'https request -> Secure');
        self::assertStringContainsString('SameSite=Lax', $first->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('Max-Age=60', $first->getHeaderLine('Set-Cookie'));

        $second = $middleware->process(new ServerRequest('GET', 'http://app.test/')->withCookieParams(['keel_session' => $cookie]), self::handler(static function (Session $s): void {
            self::assertFalse($s->isNew());
            self::assertSame(7, $s->get('user'));
            self::assertSame(['a' => 1], $s->get('cart'));
        }));
        self::assertFalse($second->hasHeader('Set-Cookie'), 'unchanged session sends no cookie');

        $tampered = substr_replace($cookie, 'x', 5, 1);
        $middleware->process(new ServerRequest('GET', '/')->withCookieParams(['keel_session' => $tampered]), self::handler(static function (Session $s): void {
            self::assertTrue($s->isNew(), 'bad signature starts a fresh session');
            self::assertNull($s->get('user'));
        }));

        $old = new CookieStorage(new CookieSigner(self::SECRET))->encode(['x' => 1], -10);
        $middleware->process(new ServerRequest('GET', '/')->withCookieParams(['keel_session' => $old]), self::handler(static function (Session $s): void {
            self::assertTrue($s->isNew(), 'expired payload is ignored');
        }));
    }

    public function testDestroyAndClearRemoveTheCookie(): void
    {
        $middleware = new SessionMiddleware(new SessionConfig(self::SECRET));
        $cookie = self::cookieOf($middleware->process(new ServerRequest('GET', '/'), self::handler(static fn(Session $s) => $s->set('a', 1))));

        $response = $middleware->process(new ServerRequest('GET', '/')->withCookieParams(['keel_session' => $cookie]), self::handler(static fn(Session $s) => $s->destroy()));
        self::assertStringContainsString('keel_session=;', $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));

        $response = $middleware->process(new ServerRequest('GET', '/')->withCookieParams(['keel_session' => $cookie]), self::handler(static fn(Session $s) => $s->clear()));
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'), 'emptied existing session is dropped');
    }

    public function testServerSideStorageKeepsOnlyASignedIdInTheCookie(): void
    {
        $storage = new ArrayStorage();
        $middleware = new SessionMiddleware(new SessionConfig(self::SECRET, storage: 'file'), $storage);

        $first = $middleware->process(new ServerRequest('GET', '/'), self::handler(static fn(Session $s) => $s->set('user', 'ann')));
        $cookie = self::cookieOf($first);
        self::assertCount(1, $storage->sessions);
        $id = array_key_first($storage->sessions);
        self::assertNotNull($id);
        self::assertStringNotContainsString('ann', $cookie, 'data stays on the server');
        self::assertSame($id, new CookieSigner(self::SECRET)->verify($cookie));

        $middleware->process(new ServerRequest('GET', '/')->withCookieParams(['keel_session' => $cookie]), self::handler(static function (Session $s) use ($id): void {
            self::assertSame($id, $s->id());
            self::assertSame('ann', $s->get('user'));
            $s->regenerate();
        }));
        self::assertArrayNotHasKey($id, $storage->sessions, 'regenerate: old id gone');
        self::assertCount(1, $storage->sessions, 'data written under the new id only');

        $forged = new CookieSigner('another-secret-also-long-enough-for-hmac!!')->sign($id);
        $middleware->process(new ServerRequest('GET', '/')->withCookieParams(['keel_session' => $forged]), self::handler(static fn(Session $s) => self::assertTrue($s->isNew())));
    }

    public function testFileStorageWritesReadsExpiresAndCollectsGarbage(): void
    {
        $dir = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/sessions-' . uniqid();
        $storage = new FileStorage($dir, gcDivisor: 0);
        $id = Session::generateId();

        $storage->write($id, ['k' => 'v'], 60);
        self::assertSame(['k' => 'v'], $storage->read($id));
        self::assertNull($storage->read('not-a-valid-id'));

        $expired = Session::generateId();
        $storage->write($expired, ['x' => 1], -1);
        self::assertNull($storage->read($expired));
        $storage->write($expired, ['x' => 1], -1);
        self::assertSame(1, $storage->gc());
        self::assertSame(['k' => 'v'], $storage->read($id));

        $storage->destroy($id);
        self::assertNull($storage->read($id));
    }

    public function testFlashAndConfigValidation(): void
    {
        $session = new Session('abc', ['flash' => 'saved']);
        self::assertSame('saved', $session->pull('flash'));
        self::assertNull($session->pull('flash', null));
        self::assertTrue($session->isChanged());

        $this->expectException(\InvalidArgumentException::class);
        new SessionConfig('short');
    }

    public function testCookieSignerSeparatesPurposesAndKeepsLegacySignaturesValid(): void
    {
        $legacy = new CookieSigner(self::SECRET);
        $session = new CookieSigner(self::SECRET, 'session');
        $remember = new CookieSigner(self::SECRET, 'remember-me');

        self::assertSame('u7', $session->verify($session->sign('u7')));
        self::assertNull($remember->verify($session->sign('u7')), 'a session value is not a remember-me token');
        self::assertNull($legacy->verify($session->sign('u7')));
        self::assertSame('u7', new CookieSigner(self::SECRET)->verify($legacy->sign('u7')), 'the default purpose is the pre-1.3 scheme');
        self::assertNull($session->verify('no-dot'));
        self::assertNull($session->verify('payload.***'));
    }

    public function testSameSiteIsValidatedAndNoneIsAlwaysSecure(): void
    {
        $middleware = new SessionMiddleware(new SessionConfig(self::SECRET, sameSite: 'None'));
        $response = $middleware->process(new ServerRequest('GET', 'http://app.test/'), self::handler(static fn(Session $s) => $s->set('a', 1)));
        self::assertStringContainsString('SameSite=None', $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('; Secure', $response->getHeaderLine('Set-Cookie'), 'None forces Secure even over http');

        foreach ([['sameSite' => 'none'], ['sameSite' => 'Weird'], ['sameSite' => 'None', 'secure' => false]] as $arguments) {
            try {
                new SessionConfig(self::SECRET, ...$arguments);
                self::fail('accepted ' . json_encode($arguments));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('ameSite', $e->getMessage());
            }
        }
    }

    public function testExpiryIsRefreshedWhenLessThanHalfOfTheTtlIsLeft(): void
    {
        $storage = new ArrayStorage();
        $middleware = new SessionMiddleware(new SessionConfig(self::SECRET, storage: 'file', ttl: 100), $storage);
        $cookie = self::cookieOf($middleware->process(new ServerRequest('GET', '/'), self::handler(static fn(Session $s) => $s->set('user', 'ann'))));
        $id = array_key_first($storage->sessions);
        self::assertNotNull($id);
        $read = self::handler(static fn(Session $s) => self::assertSame('ann', $s->get('user')));

        $storage->sessions[$id][0] = time() + 60;
        $response = $middleware->process(new ServerRequest('GET', '/')->withCookieParams(['keel_session' => $cookie]), $read);
        self::assertFalse($response->hasHeader('Set-Cookie'), 'more than half of the ttl left: nothing to do');
        self::assertSame(time() + 60, $storage->sessions[$id][0]);

        $storage->sessions[$id][0] = time() + 40;
        $response = $middleware->process(new ServerRequest('GET', '/')->withCookieParams(['keel_session' => $cookie]), $read);
        self::assertStringContainsString('Max-Age=100', $response->getHeaderLine('Set-Cookie'), 'unchanged but ageing session: cookie and storage refreshed');
        self::assertEqualsWithDelta(time() + 100, $storage->sessions[$id][0], 1);
        self::assertSame(['user' => 'ann'], $storage->sessions[$id][1]);

        // Cookie storage: a cookie issued with a short ttl is re-issued with the full one once it ages.
        $short = self::cookieOf(new SessionMiddleware(new SessionConfig(self::SECRET, ttl: 10))->process(new ServerRequest('GET', '/'), self::handler(static fn(Session $s) => $s->set('x', 1))));
        $long = new SessionMiddleware(new SessionConfig(self::SECRET, ttl: 100));
        $response = $long->process(new ServerRequest('GET', '/')->withCookieParams(['keel_session' => $short]), self::handler(static fn(Session $s) => self::assertSame(1, $s->get('x'))));
        self::assertStringContainsString('Max-Age=100', $response->getHeaderLine('Set-Cookie'));
        self::assertSame(['x' => 1], new CookieStorage(new CookieSigner(self::SECRET))->read(self::cookieOf($response)));
        self::assertNull(new CookieStorage(new CookieSigner(self::SECRET))->expiresAt('garbage'));
    }

    public function testDestroyAfterRegenerateRemovesTheOldEntryToo(): void
    {
        $storage = new ArrayStorage();
        $middleware = new SessionMiddleware(new SessionConfig(self::SECRET, storage: 'file'), $storage);
        $cookie = self::cookieOf($middleware->process(new ServerRequest('GET', '/'), self::handler(static fn(Session $s) => $s->set('user', 'ann'))));
        self::assertCount(1, $storage->sessions);

        $response = $middleware->process(new ServerRequest('GET', '/')->withCookieParams(['keel_session' => $cookie]), self::handler(static function (Session $s): void {
            $s->regenerate();
            $s->destroy();
        }));
        self::assertSame([], $storage->sessions, 'neither the old nor the new id survives');
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));

        $cookie = self::cookieOf($middleware->process(new ServerRequest('GET', '/'), self::handler(static fn(Session $s) => $s->set('user', 'bob'))));
        $middleware->process(new ServerRequest('GET', '/')->withCookieParams(['keel_session' => $cookie]), self::handler(static function (Session $s): void {
            $s->regenerate();
            $s->clear();
        }));
        self::assertSame([], $storage->sessions, 'emptied after regenerate: both entries dropped');
    }

    public function testFileStorageProtectsItsFilesAndDirectory(): void
    {
        $base = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/perm-' . uniqid();
        $dir = $base . '/sessions';
        $storage = new FileStorage($dir, gcDivisor: 0);
        $id = Session::generateId();

        $storage->write($id, ['k' => 'v'], 60);
        self::assertSame(0o700, fileperms($dir) & 0o777, 'directory is private');
        self::assertSame(0o600, fileperms($dir . '/sess_' . $id . '.json') & 0o777, 'session file is private');
        self::assertEqualsWithDelta(time() + 60, $storage->expiresAt($id), 1);
        self::assertNull($storage->expiresAt(Session::generateId()));

        $stale = $dir . '/sess_' . Session::generateId() . '.json.dead.tmp';
        $fresh = $dir . '/sess_' . Session::generateId() . '.json.live.tmp';
        touch($stale, time() - FileStorage::STALE_TEMPORARY - 60);
        touch($fresh);
        self::assertSame(1, $storage->gc(), 'only the stale temporary file is a leftover');
        self::assertFileDoesNotExist($stale);
        self::assertFileExists($fresh);
        self::assertSame(['k' => 'v'], $storage->read($id));

        $link = $base . '/link';
        symlink($dir, $link);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('symbolic link');
        new FileStorage($link, gcDivisor: 0)->write(Session::generateId(), [], 60);
    }

    public function testCookieSessionTooLargeIsRejected(): void
    {
        $middleware = new SessionMiddleware(new SessionConfig(self::SECRET));

        $this->expectException(\OverflowException::class);
        $middleware->process(new ServerRequest('GET', '/'), self::handler(static fn(Session $s) => $s->set('blob', str_repeat('x', 5000))));
    }


    public function testTtlMustBeAtLeastOneSecond(): void
    {
        self::assertSame(1, new SessionConfig(self::SECRET, ttl: 1)->ttl);

        foreach ([0, -1, \PHP_INT_MIN] as $ttl) {
            try {
                new SessionConfig(self::SECRET, ttl: $ttl);
                self::fail('accepted ttl ' . $ttl);
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Session ttl must be at least 1 second, got ' . $ttl, $e->getMessage());
            }
        }
    }
}
