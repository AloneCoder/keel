<?php

declare(strict_types=1);

namespace Keel\Tests\Security;

use Keel\Auth\ApiKeyAuthenticator;
use Keel\Auth\Attribute\Auth;
use Keel\Auth\Attribute\Can;
use Keel\Auth\AuthConfig;
use Keel\Auth\AuthMiddleware;
use Keel\Auth\Jwt\JwksKeySet;
use Keel\Auth\PrincipalResolver;
use Keel\Auth\SignedUrlConfig;
use Keel\Auth\SignedUrlGuard;
use Keel\Auth\UrlSigner;
use Keel\Cache\MemcachedCache;
use Keel\Container\Definitions;
use Keel\Http\CallableHandler;
use Keel\Http\Exception\ForbiddenException;
use Keel\Http\Middleware\TrustedProxiesConfig;
use Keel\Http\Middleware\TrustedProxiesMiddleware;
use Keel\I18n\ArrayTranslator;
use Keel\I18n\I18nConfig;
use Keel\Kernel\Kernel;
use Keel\Routing\Exception\RoutingException;
use Keel\Routing\Route;
use Keel\Routing\Router;
use Keel\Runtime\GlobalsRequestCreator;
use Keel\Telemetry\TracingMiddleware;
use Keel\Testing\TestClient;
use Keel\Tests\Auth\Fixtures\Tokens;
use Keel\Tests\Security\Fixtures\AdminAreaController;
use Keel\Tests\Security\Fixtures\FeedController;
use Keel\Tests\Security\Fixtures\ReportController;
use Keel\Tests\Security\Fixtures\ReportPolicy;
use Keel\Validation\Alnum;
use Keel\Validation\Hostname;
use Keel\Validation\Uuid;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7Server\ServerRequestCreatorInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Defence in depth: requirements that must not loosen, values that must not slip past their
 * constraints, and data that must not leave the process (tracing, redirects, catalogue paths).
 */
#[Group('security')]
final class HardeningTest extends TestCase
{
    private function client(): TestClient
    {
        $definitions = new Definitions();
        $definitions->set(Tokens::class)->alias(PrincipalResolver::class);
        $definitions->set(ReportPolicy::class);
        $definitions->instance(AuthConfig::class, new AuthConfig([ApiKeyAuthenticator::class]));

        return TestClient::forKernel(Kernel::build($definitions, [AdminAreaController::class, ReportController::class, FeedController::class], [AuthMiddleware::class]));
    }

    public function testAMethodAuthOrCanAddsToTheClassRequirementInsteadOfReplacingIt(): void
    {
        $client = $this->client();
        $client->get('/admin/users', ['X-Api-Key' => 'user-token'])->assertStatus(403);
        $client->get('/admin/users', ['X-Api-Key' => 'admin-token'])->assertOk();
        $client->get('/admin/audit', ['X-Api-Key' => 'admin-token'])->assertStatus(403);
        $client->get('/reports/1', ['X-Api-Key' => 'user-token'])->assertStatus(403);
        $client->get('/reports/1', ['X-Api-Key' => 'admin-token'])->assertOk()->assertJson(['report' => '1']);

        $routes = Router::fromControllers(AdminAreaController::class, ReportController::class);
        self::assertSame(['roles' => ['admin']], $routes->match('GET', '/admin/users')->match?->route->option(Auth::KEY));
        self::assertSame(['roles' => ['auditor'], 'also' => [['admin']]], $routes->match('GET', '/admin/audit')->match?->route->option(Auth::KEY));
        self::assertSame(['ability' => 'report.view', 'also' => ['admin.area']], $routes->match('GET', '/reports/1')->match?->route->option(Can::KEY));
    }

    public function testAnAnonymousRequestGetsNullForAConcretePrincipalParameter(): void
    {
        $client = $this->client();
        $client->get('/feed')->assertOk()->assertJson(['feed' => 'public', 'for' => null]);
        $client->get('/feed', ['X-Api-Key' => 'user-token'])->assertJson(['feed' => 'personal', 'for' => 'u1']);
    }

    public function testOnlyATrustedProxyChoosesTheScheme(): void
    {
        $creator = Kernel::build(new Definitions(), [])->container->get(ServerRequestCreatorInterface::class);
        self::assertInstanceOf(GlobalsRequestCreator::class, $creator, 'what the runtimes build requests with');
        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/x', 'HTTP_HOST' => 'app.test', 'SERVER_PORT' => '80', 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'javascript'];

        $request = $creator->fromArrays($server, ['Host' => 'app.test', 'X-Forwarded-Proto' => 'javascript']);
        self::assertSame('http://app.test/x', (string) $request->getUri());
        self::assertSame('javascript', $request->getHeaderLine('X-Forwarded-Proto'), 'the header stays for TrustedProxiesMiddleware');

        $globals = $_SERVER;
        try {
            $_SERVER = $server;
            self::assertSame('http', $creator->fromGlobals()->getUri()->getScheme());
            self::assertSame('javascript', $_SERVER['HTTP_X_FORWARDED_PROTO'], 'the superglobal is left as it was');
        } finally {
            $_SERVER = $globals;
        }

        $seen = null;
        $proxies = new TrustedProxiesMiddleware(new TrustedProxiesConfig(['10.0.0.0/8']));
        $handler = new CallableHandler(static function (ServerRequestInterface $request) use (&$seen): ResponseInterface {
            $seen = $request->getUri()->getScheme();

            return new Psr17Factory()->createResponse();
        });
        $proxies->process($creator->fromArrays(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https'] + $server, ['X-Forwarded-Proto' => 'https']), $handler);
        self::assertSame('https', $seen, 'a trusted proxy still sets it');
        $proxies->process($creator->fromArrays(['HTTP_X_FORWARDED_PROTO' => 'https'] + $server, ['X-Forwarded-Proto' => 'https']), $handler);
        self::assertSame('http', $seen, 'any other client does not');
    }

    public function testEncodedCharactersCannotSlipPastAPlaceholderConstraint(): void
    {
        $router = Router::fromRoutes([
            new Route('/files/{name:[^./]+}', ['GET'], [FeedController::class, 'feed']),
            new Route('/avatars/{user}', ['GET'], [FeedController::class, 'feed']),
        ]);

        self::assertNull($router->match('GET', '/files/%2E%2E%2F%2E%2E%2Fetc%2Fpasswd')->match);
        self::assertNull($router->match('GET', '/avatars/config%00')->match, 'no value decodes to a NUL byte');
        self::assertSame(['name' => 'report'], $router->match('GET', '/files/report')->match?->params);
        self::assertSame(['user' => 'a/b'], $router->match('GET', '/avatars/a%2Fb')->match?->params, 'an encoded slash stays inside a default placeholder');
    }

    public function testARegexFailureNeverFallsThroughToALaterRoute(): void
    {
        $routes = [
            new Route('/files/{a}-{b}-{c}', ['GET'], [FeedController::class, 'feed']),
            new Route('/files/{path:.+}', ['GET'], [AdminAreaController::class, 'users']),
        ];
        for ($i = 0; $i < 8; $i++) {
            $routes[] = new Route('/filler' . $i . '/{x}', ['GET'], [FeedController::class, 'feed']);
        }
        $routes[] = new Route('/{any:.+}', ['GET'], [FeedController::class, 'feed']); // the next chunk

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('Route matching failed');
        Router::fromRoutes($routes)->match('GET', '/files/' . str_repeat('a-', 3000) . '/c');
    }

    public function testMemcachedCacheTreatsAnUnreachableServerAsAMiss(): void
    {
        if (!extension_loaded('memcached')) {
            self::markTestSkipped('ext-memcached is not loaded');
        }
        $memcached = new \Memcached();
        $memcached->addServer('127.0.0.1', 1);
        $cache = new MemcachedCache($memcached);

        self::assertSame('default', $cache->get('ban.u1', 'default'));
        self::assertFalse($cache->has('ban.u1'));
        self::assertSame('computed', $cache->remember('ban.u1', 60, static fn(): string => 'computed'));
        self::assertFalse($cache->delete('ban.u1'), 'a delete that did not happen is not reported as done');

        $name = new \ReflectionMethod(MemcachedCache::class, 'name');
        self::assertSame('keel:cache:' . hash('sha256', 'ban.john doe'), $name->invoke($cache, 'ban.john doe'), 'a key Memcached would refuse is hashed');
        self::assertSame('keel:cache:ban.u1', $name->invoke($cache, 'ban.u1'));
    }

    public function testValidatorsRefuseATrailingNewline(): void
    {
        self::assertNull(new Alnum()->check('abc'));
        self::assertNotNull(new Alnum()->check("abc\n"));
        self::assertNull(new Uuid()->check('123e4567-e89b-12d3-a456-426614174000'));
        self::assertNotNull(new Uuid()->check("123e4567-e89b-12d3-a456-426614174000\n"));
        self::assertNull(new Hostname()->check('example.com'));
        self::assertNotNull(new Hostname()->check("example.com\n"));
    }

    public function testAnUnreachableIdentityProviderIsNotAskedForEveryToken(): void
    {
        $http = new class implements ClientInterface {
            public int $calls = 0;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->calls++;

                throw new \RuntimeException('connection refused');
            }
        };
        $clock = new class implements ClockInterface {
            public int $now = 1_800_000_000;

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@' . $this->now);
            }
        };
        $keys = new JwksKeySet('https://idp.test/jwks', $http, new Psr17Factory(), clock: $clock);

        for ($i = 0; $i < 20; $i++) {
            try {
                $keys->keysFor('kid-' . $i, 'RS256');
                self::fail('no keys without the provider');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('connection refused', $e->getMessage());
            }
        }
        self::assertSame(1, $http->calls);

        $clock->now += 5;
        try {
            $keys->keysFor('kid', 'RS256');
        } catch (\RuntimeException) {
        }
        self::assertSame(2, $http->calls, 'asked again a few seconds later');
    }

    public function testTracingKeepsSecretsOutOfTheBackendAndClientsOutOfSpanNames(): void
    {
        $exporter = new InMemoryExporter();
        $definitions = new Definitions();
        $definitions->instance(TracerProviderInterface::class, new TracerProvider(new SimpleSpanProcessor($exporter)));
        $kernel = Kernel::build($definitions, [FeedController::class], [TracingMiddleware::class]);

        $kernel->handle(new ServerRequest('GET', 'https://app.test/feed?expires=1893456000&signature=9f86d0&Access_Token=t&page=2'));
        $kernel->handle(new ServerRequest('Xac2d6c3f', 'https://app.test/feed'));

        [$signed, $odd] = $exporter->getSpans();
        self::assertInstanceOf(ImmutableSpan::class, $signed);
        self::assertInstanceOf(ImmutableSpan::class, $odd);
        self::assertSame('GET /feed', $signed->getName());
        self::assertSame('expires=1893456000&signature=REDACTED&Access_Token=REDACTED&page=2', $signed->getAttributes()->get('url.query'));
        self::assertSame('HTTP', $odd->getName());
        self::assertSame('_OTHER', $odd->getAttributes()->get('http.request.method'));
        self::assertSame('Xac2d6c3f', $odd->getAttributes()->get('http.request.method_original'));
    }

    public function testAnExplicitLocaleNeverLeavesTheConfiguredOnes(): void
    {
        $dir = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/i18n-' . bin2hex(random_bytes(4));
        mkdir($dir . '/lang/en', 0o775, true);
        mkdir($dir . '/elsewhere', 0o775, true);
        file_put_contents($dir . '/lang/en/messages.php', "<?php return ['hello' => 'Hello'];");
        file_put_contents($dir . '/elsewhere/messages.php', "<?php return ['hello' => 'planted'];");
        $translator = new ArrayTranslator(new I18nConfig($dir . '/lang', ['en', 'ru']));

        self::assertSame('Hello', $translator->trans('hello', locale: '../elsewhere'));
        for ($i = 0; $i < 50; $i++) {
            $translator->trans('hello', locale: 'x' . $i);
        }
        $catalogues = new \ReflectionProperty(ArrayTranslator::class, 'catalogues');
        self::assertSame(['en'], array_keys((array) $catalogues->getValue($translator)), 'only configured locales are cached');
    }

    public function testASignedGetLinkOpensNoOtherMethod(): void
    {
        $signer = new UrlSigner(new SignedUrlConfig('signed-url-secret-that-is-long-enough'));
        $link = $signer->sign('/files/42');
        self::assertTrue($signer->verify($link));
        self::assertTrue($signer->verify($link, 'HEAD'));
        self::assertFalse($signer->verify($link, 'DELETE'));

        $delete = $signer->sign('/files/42', method: 'DELETE');
        self::assertTrue($signer->verify($delete, 'DELETE'));
        self::assertFalse($signer->verify($delete), 'nor does a DELETE link open GET');

        $this->expectException(ForbiddenException::class);
        new SignedUrlGuard($signer)->process(new ServerRequest('DELETE', $link), new CallableHandler(static fn(): ResponseInterface => new Psr17Factory()->createResponse()));
    }
}
