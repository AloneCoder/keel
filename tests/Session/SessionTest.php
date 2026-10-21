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

        $expired = new SessionMiddleware(new SessionConfig(self::SECRET, ttl: -10));
        $old = self::cookieOf($expired->process(new ServerRequest('GET', '/'), self::handler(static fn(Session $s) => $s->set('x', 1))));
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

    public function testCookieSessionTooLargeIsRejected(): void
    {
        $middleware = new SessionMiddleware(new SessionConfig(self::SECRET));

        $this->expectException(\OverflowException::class);
        $middleware->process(new ServerRequest('GET', '/'), self::handler(static fn(Session $s) => $s->set('blob', str_repeat('x', 5000))));
    }
}
