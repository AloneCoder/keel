<?php

declare(strict_types=1);

namespace Keel\Tests\HttpClient;

use Keel\Container\Container;
use Keel\Container\Definitions;
use Keel\HttpClient\CurlClient;
use Keel\HttpClient\HttpClientConfig;
use Keel\HttpClient\HttpClientProvider;
use Keel\HttpClient\NetworkException;
use Keel\HttpClient\RequestException;
use Keel\Kernel\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;

#[CoversClass(CurlClient::class)]
#[CoversClass(HttpClientConfig::class)]
#[CoversClass(HttpClientProvider::class)]
#[CoversClass(NetworkException::class)]
#[CoversClass(RequestException::class)]
#[RequiresPhpExtension('curl')]
final class CurlClientTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            self::markTestSkipped('cannot allocate a port: ' . $error);
        }
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        self::$base = 'http://' . $address;

        $command = [\PHP_BINARY, '-S', $address, __DIR__ . '/Fixtures/server.php'];
        $process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if ($process === false) {
            self::markTestSkipped('cannot start the test server');
        }
        self::$server = $process;

        $deadline = microtime(true) + 5;
        do {
            $probe = @stream_socket_client('tcp://' . $address, timeout: 0.2);
            if ($probe !== false) {
                fclose($probe);

                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        self::markTestSkipped('test server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    private function client(?HttpClientConfig $config = null): CurlClient
    {
        $factory = new Psr17Factory();

        return new CurlClient($factory, $factory, $config);
    }

    /**
     * @return array<string, mixed>
     */
    private static function echoed(string $body): array
    {
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testGetWithDefaultAndRequestHeaders(): void
    {
        $client = $this->client(new HttpClientConfig(headers: ['X-Default' => 'd', 'Accept' => 'text/plain'], userAgent: 'keel-test'));
        $response = $client->sendRequest(new Request('GET', self::$base . '/echo?a=1&b=two', ['Accept' => 'application/json']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('1.1', $response->getProtocolVersion());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $echo = self::echoed((string) $response->getBody());
        self::assertSame('GET', $echo['method']);
        self::assertSame(['a' => '1', 'b' => 'two'], $echo['query']);
        self::assertIsArray($echo['headers']);
        self::assertSame('d', $echo['headers']['x-default']);
        self::assertSame('application/json', $echo['headers']['accept'], 'request header wins over the default');
        self::assertSame('keel-test', $echo['headers']['user-agent']);
    }

    public function testPostBodyAndContentType(): void
    {
        $response = $this->client()->sendRequest(new Request('POST', self::$base . '/echo', ['Content-Type' => 'application/json'], '{"n":1}'));
        $echo = self::echoed((string) $response->getBody());
        self::assertSame('POST', $echo['method']);
        self::assertSame('{"n":1}', $echo['body']);
        self::assertSame('application/json', $echo['content_type']);

        $empty = self::echoed((string) $this->client()->sendRequest(new Request('PUT', self::$base . '/echo'))->getBody());
        self::assertSame('PUT', $empty['method']);
        self::assertSame('', $empty['body']);
    }

    public function testStatusCodesHeadersAndHeadAreReturnedNotThrown(): void
    {
        $response = $this->client()->sendRequest(new Request('GET', self::$base . '/status?code=503'));
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('nope', (string) $response->getBody());
        self::assertSame('yes', $response->getHeaderLine('X-Custom'));
        self::assertSame(['a', 'b'], $response->getHeader('X-Multi'));

        $head = $this->client()->sendRequest(new Request('HEAD', self::$base . '/status?code=404'));
        self::assertSame(404, $head->getStatusCode());
        self::assertSame('', (string) $head->getBody());
    }

    public function testRedirectsFollowTheConfig(): void
    {
        $followed = $this->client()->sendRequest(new Request('GET', self::$base . '/redirect'));
        self::assertSame(200, $followed->getStatusCode());
        self::assertSame(['from' => 'redirect'], self::echoed((string) $followed->getBody())['query']);

        $stopped = $this->client(new HttpClientConfig(maxRedirects: 0))->sendRequest(new Request('GET', self::$base . '/redirect'));
        self::assertSame(302, $stopped->getStatusCode());
        self::assertSame('/echo?from=redirect', $stopped->getHeaderLine('Location'));
    }

    public function testTimeoutAndConnectionFailuresAreNetworkExceptions(): void
    {
        $client = $this->client(new HttpClientConfig(timeout: 0.1));
        try {
            $client->sendRequest(new Request('GET', self::$base . '/slow'));
            self::fail('timeout expected');
        } catch (NetworkException $e) {
            self::assertSame(self::$base . '/slow', (string) $e->getRequest()->getUri());
            self::assertSame(\CURLE_OPERATION_TIMEDOUT, $e->getCode());
        }

        usleep(400_000); // php -S is single-threaded: let it finish the slow request
        self::assertSame(200, $client->sendRequest(new Request('GET', self::$base . '/echo'))->getStatusCode(), 'the client recovers after a failure');

        $this->expectException(NetworkException::class);
        $this->client()->sendRequest(new Request('GET', 'http://127.0.0.1:1/'));
    }

    public function testUnsupportedSchemeIsARequestException(): void
    {
        $this->expectException(RequestException::class);
        $this->client()->sendRequest(new Request('GET', 'ftp://example.test/file'));
    }

    public function testProviderWiresThePsr18Interface(): void
    {
        $definitions = new Definitions();
        Kernel::registerDefaults($definitions);
        $definitions->instance(HttpClientConfig::class, new HttpClientConfig(userAgent: 'wired'));
        $definitions->load(new HttpClientProvider());
        $container = new Container($definitions);

        $client = $container->get(ClientInterface::class);
        self::assertInstanceOf(CurlClient::class, $client);
        $echo = self::echoed((string) $client->sendRequest(new Request('GET', self::$base . '/echo'))->getBody());
        self::assertIsArray($echo['headers']);
        self::assertSame('wired', $echo['headers']['user-agent']);
    }
}
