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
        self::assertSame(['a=1', 'b=2'], $response->getHeader('Set-Cookie'), 'header names differing in case are one header');

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

        $limited = $this->client(new HttpClientConfig(maxRedirects: 3))->sendRequest(new Request('GET', self::$base . '/loop'));
        self::assertSame(302, $limited->getStatusCode(), 'the last redirect is returned once the limit is reached');
    }

    public function testRedirectsChangeTheMethodAndDropCredentialsLikeABrowser(): void
    {
        $headers = ['Authorization' => 'Bearer secret', 'Cookie' => 'sid=1', 'X-Api-Key' => 'k', 'Content-Type' => 'text/plain'];

        $seeOther = self::echoed((string) $this->client()->sendRequest(new Request('POST', self::$base . '/redirect?to=/echo%3Fhop%3D1&code=303', $headers, 'payload'))->getBody());
        self::assertSame('GET', $seeOther['method'], '303 turns a POST into a GET');
        self::assertSame('', $seeOther['body']);
        self::assertSame(['hop' => '1'], $seeOther['query']);
        self::assertIsArray($seeOther['headers']);
        self::assertArrayNotHasKey('content-type', $seeOther['headers']);
        self::assertSame('Bearer secret', $seeOther['headers']['authorization'], 'same origin keeps the credentials');
        self::assertSame('sid=1', $seeOther['headers']['cookie']);

        $found = self::echoed((string) $this->client()->sendRequest(new Request('POST', self::$base . '/redirect?to=/echo&code=302', $headers, 'payload'))->getBody());
        self::assertSame('GET', $found['method'], '302 after a POST is a GET as well');

        $repeated = self::echoed((string) $this->client()->sendRequest(new Request('POST', self::$base . '/redirect?to=/echo&code=307', $headers, 'payload'))->getBody());
        self::assertSame('POST', $repeated['method'], '307 repeats the request');
        self::assertSame('payload', $repeated['body']);
        self::assertSame('text/plain', $repeated['content_type']);

        $relative = self::echoed((string) $this->client()->sendRequest(new Request('GET', self::$base . '/redirect?to=../echo%3Fr%3D1'))->getBody());
        self::assertSame('/echo', $relative['path'], 'relative Location is resolved against the request URI');
        self::assertSame(['r' => '1'], $relative['query']);

        $other = 'http://localhost:' . (string) parse_url(self::$base, \PHP_URL_PORT) . '/echo';
        $crossOrigin = self::echoed((string) $this->client()->sendRequest(new Request('GET', self::$base . '/redirect?to=' . rawurlencode($other), $headers))->getBody());
        self::assertIsArray($crossOrigin['headers']);
        self::assertArrayNotHasKey('authorization', $crossOrigin['headers'], 'Authorization is not sent to another origin');
        self::assertArrayNotHasKey('cookie', $crossOrigin['headers']);
        self::assertSame('k', $crossOrigin['headers']['x-api-key'], 'other headers travel with the request');

        $ftp = $this->client()->sendRequest(new Request('GET', self::$base . '/redirect?to=' . rawurlencode('ftp://example.test/file')));
        self::assertSame(302, $ftp->getStatusCode(), 'only http(s) targets are followed');
    }

    public function testResponseHeaderParsingJoinsFoldedLinesAndRejectsMalformedHeaders(): void
    {
        $client = $this->client();
        $parse = new \ReflectionMethod($client, 'response');
        $request = new Request('GET', 'http://example.test/');

        $response = $parse->invoke($client, [
            "HTTP/1.1 100 Continue\r\n",
            "\r\n",
            "HTTP/1.1 201 Created\r\n",
            "X-Long: first part\r\n",
            "  second part\r\n",
            "Set-Cookie: a=1\r\n",
            "SET-COOKIE: b=2\r\n",
            "garbage without colon\r\n",
            "\r\n",
        ], 'body', $request);
        self::assertInstanceOf(\Psr\Http\Message\ResponseInterface::class, $response);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Created', $response->getReasonPhrase());
        self::assertSame('first part second part', $response->getHeaderLine('X-Long'));
        self::assertSame(['a=1', 'b=2'], $response->getHeader('set-cookie'));
        self::assertSame('body', (string) $response->getBody());

        $this->expectException(RequestException::class);
        $parse->invoke($client, ["HTTP/1.1 200 OK\r\n", "Bad\x01Name: v\r\n"], '', $request);
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
