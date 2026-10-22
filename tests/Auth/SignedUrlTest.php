<?php

declare(strict_types=1);

namespace Keel\Tests\Auth;

use Keel\Auth\Attribute\Signed;
use Keel\Auth\SignedUrlConfig;
use Keel\Auth\SignedUrlGuard;
use Keel\Auth\SignedUrlStatus;
use Keel\Auth\UrlSigner;
use Keel\Container\Definitions;
use Keel\Http\Exception\ForbiddenException;
use Keel\Kernel\Kernel;
use Keel\OpenApi\Generator;
use Keel\Routing\Router;
use Keel\Session\CookieSigner;
use Keel\Testing\TestClient;
use Keel\Tests\Auth\Fixtures\DownloadController;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(UrlSigner::class)]
#[CoversClass(SignedUrlConfig::class)]
#[CoversClass(SignedUrlStatus::class)]
#[CoversClass(SignedUrlGuard::class)]
#[CoversClass(Signed::class)]
#[CoversClass(CookieSigner::class)]
#[CoversClass(ForbiddenException::class)]
#[CoversClass(Generator::class)]
final class SignedUrlTest extends TestCase
{
    private const string SECRET = 'a-signed-url-secret-of-32-chars!!';

    private int $now = 1_800_000_000;

    private function clock(): ClockInterface
    {
        return new class ($this->now(...)) implements ClockInterface {
            public function __construct(private readonly \Closure $now) {}

            public function now(): \DateTimeImmutable
            {
                /** @var int $now */
                $now = ($this->now)();

                return new \DateTimeImmutable('@' . $now);
            }
        };
    }

    private function now(): int
    {
        return $this->now;
    }

    private function signer(?SignedUrlConfig $config = null): UrlSigner
    {
        return new UrlSigner($config ?? new SignedUrlConfig(self::SECRET), Router::fromControllers(DownloadController::class), $this->clock());
    }

    public function testRouteLinksAreSignedAndVerified(): void
    {
        $signer = $this->signer();
        $url = $signer->route('downloads.show', ['id' => 5, 'format' => 'pdf']);

        self::assertMatchesRegularExpression('~^/downloads/5\?format=pdf&signature=[A-Za-z0-9_-]{43}$~', $url);
        self::assertSame(SignedUrlStatus::Valid, $signer->status($url));
        self::assertTrue($signer->verify(new Uri('https://anywhere.example' . $url)), 'scheme and host are not signed');
        self::assertTrue($signer->verify($url . '#fragment'));
        self::assertSame($url, $signer->route('downloads.show', ['id' => 5, 'format' => 'pdf']), 'deterministic');

        $bare = $signer->sign('/files/a');
        self::assertMatchesRegularExpression('~^/files/a\?signature=~', $bare);
        self::assertTrue($signer->verify($bare));
    }

    public function testAlteredLinksAreRefused(): void
    {
        $signer = $this->signer();
        $url = $signer->route('downloads.show', ['id' => 5, 'format' => 'pdf'], expires: 60);
        [$path, $query] = explode('?', $url, 2);

        self::assertSame(SignedUrlStatus::Invalid, $signer->status(str_replace('/5?', '/6?', $url)), 'another path');
        self::assertSame(SignedUrlStatus::Invalid, $signer->status(str_replace('pdf', 'zip', $url)), 'another value');
        self::assertSame(SignedUrlStatus::Invalid, $signer->status($path . '?admin=1&' . $query), 'an added parameter');
        self::assertSame(SignedUrlStatus::Invalid, $signer->status((string) preg_replace('~expires=\d+&~', '', $url)), 'the expiry removed');
        self::assertSame(SignedUrlStatus::Invalid, $signer->status((string) preg_replace('~expires=(\d+)~', 'expires=9999999999', $url)), 'the expiry pushed back');
        self::assertSame(SignedUrlStatus::Invalid, $signer->status($url . '&signature=x'), 'two signatures');
        self::assertSame(SignedUrlStatus::Invalid, $signer->status($path . '?format=pdf&expires=1&' . substr($query, strlen('format=pdf&'))), 'two expiries');
        self::assertSame(SignedUrlStatus::Missing, $signer->status($path . '?format=pdf'));
        self::assertSame(SignedUrlStatus::Missing, $signer->status('/downloads/5'));

        $other = $this->signer(new SignedUrlConfig(self::SECRET, purpose: 'other'));
        self::assertSame(SignedUrlStatus::Invalid, $other->status($url), 'another purpose, another key');
        self::assertSame(SignedUrlStatus::Invalid, $this->signer(new SignedUrlConfig(str_repeat('x', 32)))->status($url), 'another secret');
    }

    public function testLinksExpire(): void
    {
        $signer = $this->signer();
        $url = $signer->route('downloads.show', ['id' => 5], expires: 60);
        self::assertStringContainsString('expires=' . ($this->now + 60) . '&signature=', $url);
        self::assertTrue($signer->verify($url));

        $this->now += 59;
        self::assertTrue($signer->verify($url));
        $this->now += 1;
        self::assertSame(SignedUrlStatus::Expired, $signer->status($url));

        $at = $signer->sign('/files/a', new \DateTimeImmutable('@' . ($this->now + 10)));
        self::assertStringContainsString('expires=' . ($this->now + 10), $at);

        $defaulted = $this->signer(new SignedUrlConfig(self::SECRET, ttl: 3600))->sign('/files/a');
        self::assertStringContainsString('expires=' . ($this->now + 3600), $defaulted);
        $this->now += 3600;
        self::assertSame(SignedUrlStatus::Expired, $this->signer(new SignedUrlConfig(self::SECRET, ttl: 3600))->status($defaulted));
    }

    public function testBaseUrlPrefixesLinksWithoutBeingSigned(): void
    {
        $signer = $this->signer(new SignedUrlConfig(self::SECRET, baseUrl: 'https://example.com/app/'));
        $url = $signer->route('downloads.show', ['id' => 7]);

        self::assertStringStartsWith('https://example.com/app/downloads/7?signature=', $url);
        self::assertTrue($signer->verify($url), 'the base URL is stripped before checking');
        self::assertTrue($signer->verify(substr($url, strlen('https://example.com/app'))), 'what the application receives behind the proxy');
        self::assertTrue($signer->verify(new Uri(substr($url, strlen('https://example.com/app')))));
        self::assertFalse($signer->verify('https://example.com/elsewhere/downloads/7?' . explode('?', $url, 2)[1]));
    }

    public function testSigningRefusesWhatCannotBeALink(): void
    {
        $signer = $this->signer();
        foreach (['files/a', '//evil.example/x', 'https://example.com/x', '/x#frag', '/x?signature=1', '/x?a=1&expires=2'] as $path) {
            try {
                $signer->sign($path);
                self::fail(sprintf('"%s" should be refused', $path));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        $signer->sign('/x', 0);
    }

    public function testRouteNeedsTheRouter(): void
    {
        $this->expectException(\LogicException::class);
        new UrlSigner(new SignedUrlConfig(self::SECRET))->route('downloads.show', ['id' => 1]);
    }

    public function testConfigIsValidated(): void
    {
        foreach ([
            static fn(): SignedUrlConfig => new SignedUrlConfig('short'),
            static fn(): SignedUrlConfig => new SignedUrlConfig(self::SECRET, ttl: 0),
            static fn(): SignedUrlConfig => new SignedUrlConfig(self::SECRET, baseUrl: 'example.com'),
            static fn(): SignedUrlConfig => new SignedUrlConfig(self::SECRET, baseUrl: 'https://example.com/?x=1'),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCookieSignerGivesADetachedSignature(): void
    {
        $signer = new CookieSigner(self::SECRET, 'signed-url');
        $signature = $signer->signature('/files/a?');

        self::assertMatchesRegularExpression('~^[A-Za-z0-9_-]{43}$~', $signature);
        self::assertSame($signature, $signer->signature('/files/a?'));
        self::assertNotSame($signature, new CookieSigner(self::SECRET)->signature('/files/a?'));
        self::assertSame('value', $signer->verify($signer->sign('value')), 'sign() and verify() are unchanged');
    }

    public function testSignedRoutesAnswerOnlySignedLinks(): void
    {
        $definitions = new Definitions();
        $definitions->instance(SignedUrlConfig::class, new SignedUrlConfig(self::SECRET));
        $definitions->instance(ClockInterface::class, $this->clock());
        $kernel = Kernel::build($definitions, [DownloadController::class]);
        $client = TestClient::forKernel($kernel);
        $signer = $kernel->container->get(UrlSigner::class);
        self::assertInstanceOf(UrlSigner::class, $signer);

        $client->get($signer->route('downloads.show', ['id' => 3]))->assertOk()->assertJson(['download' => 3]);
        $client->get('/downloads/3')->assertProblem(403, 'This URL must be signed');
        $client->get(str_replace('/3?', '/4?', $signer->route('downloads.show', ['id' => 3])))->assertProblem(403, 'Invalid URL signature');

        $expiring = $signer->route('downloads.show', ['id' => 3], expires: 5);
        $this->now += 5;
        $client->get($expiring)->assertProblem(403, 'This link has expired');
        $client->get('/files/readme')->assertOk();
    }

    public function testOpenApiDocumentsTheSignature(): void
    {
        $document = new Generator(Router::fromControllers(DownloadController::class))->generate();
        self::assertInstanceOf(\stdClass::class, $document['paths']);
        $paths = (array) $document['paths'];
        self::assertIsArray($paths['/downloads/{id}']);
        $operation = $paths['/downloads/{id}']['get'] ?? null;
        self::assertIsArray($operation);
        self::assertIsArray($operation['parameters']);

        $query = array_values(array_filter($operation['parameters'], static fn(mixed $p): bool => is_array($p) && ($p['in'] ?? null) === 'query'));
        self::assertSame([UrlSigner::EXPIRES, UrlSigner::SIGNATURE], array_column($query, 'name'));
        self::assertSame([false, true], array_column($query, 'required'));
        self::assertIsArray($operation['responses']);
        self::assertArrayHasKey('403', $operation['responses']);

        self::assertIsArray($paths['/files/{name}']);
        $plain = $paths['/files/{name}']['get'] ?? null;
        self::assertIsArray($plain);
        self::assertIsArray($plain['responses']);
        self::assertArrayNotHasKey('403', $plain['responses']);
    }
}
