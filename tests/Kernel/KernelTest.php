<?php

declare(strict_types=1);

namespace Keel\Tests\Kernel;

use Keel\Container\Definitions;
use Keel\Http\ArgumentResolver;
use Keel\Kernel\DispatchHandler;
use Keel\Kernel\ErrorPage;
use Keel\Kernel\ExceptionHandlerMiddleware;
use Keel\Kernel\Kernel;
use Keel\Kernel\ProblemDetails;
use Keel\Kernel\RoutingMiddleware;
use Keel\Tests\Kernel\Fixtures\ArrayLogger;
use Keel\Tests\Kernel\Fixtures\Clock;
use Keel\Tests\Kernel\Fixtures\GlobalMiddleware;
use Keel\Tests\Kernel\Fixtures\RequestCounter;
use Keel\Tests\Kernel\Fixtures\UserController;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

#[CoversClass(Kernel::class)]
#[CoversClass(RoutingMiddleware::class)]
#[CoversClass(DispatchHandler::class)]
#[CoversClass(ExceptionHandlerMiddleware::class)]
#[CoversClass(ProblemDetails::class)]
#[CoversClass(ErrorPage::class)]
#[CoversClass(ArgumentResolver::class)]
final class KernelTest extends TestCase
{
    private ArrayLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new ArrayLogger();
    }

    private function kernel(bool $debug = false): Kernel
    {
        $definitions = new Definitions();
        $definitions->set(Clock::class);
        $definitions->instance(LoggerInterface::class, $this->logger);

        return Kernel::build($definitions, [UserController::class], [GlobalMiddleware::class], $debug);
    }

    /**
     * @return array<mixed>
     */
    private static function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testRoutesToControllerWithCastParamsAndMiddlewareOrder(): void
    {
        $response = $this->kernel()->handle(new ServerRequest('GET', '/api/users/42'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['id' => 42, 'trace' => ['global', 'class'], 'hits' => 1], self::json($response));
        self::assertSame(['class', 'global'], $response->getHeader('X-Trace'));
    }

    public function testCreateReturns201WithHydratedBody(): void
    {
        $request = new ServerRequest('POST', '/api/users', ['Content-Type' => 'application/json'], json_encode([
            'name' => 'Ann',
            'email' => 'a@b.c',
            'address' => ['city' => 'Oslo', 'zip' => '0150'],
            'role' => 'admin',
        ], \JSON_THROW_ON_ERROR));

        $response = $this->kernel()->handle($request);

        self::assertSame(201, $response->getStatusCode());
        $body = self::json($response);
        self::assertSame('Ann', $body['name']);
        self::assertSame(['city' => 'Oslo', 'zip' => '0150'], $body['address']);
        self::assertSame('admin', $body['role']);
    }

    public function testInvalidBodyIs400WithErrors(): void
    {
        $request = new ServerRequest('POST', '/api/users', ['Content-Type' => 'application/json'], '{"name":"x","age":"old"}');

        $response = $this->kernel()->handle($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        $body = self::json($response);
        self::assertSame('Invalid request data', $body['detail']);
        self::assertSame(['email: field is required', 'age: must be an integer'], $body['errors']);
    }

    public function testRawArrayBodyAndNullResult(): void
    {
        $kernel = $this->kernel();

        $put = $kernel->handle(new ServerRequest('PUT', '/api/users/3', ['Content-Type' => 'application/json'], '{"k":"v"}'));
        self::assertSame(['id' => 3, 'body' => ['k' => 'v']], self::json($put));

        $delete = $kernel->handle(new ServerRequest('DELETE', '/api/users/3'));
        self::assertSame(204, $delete->getStatusCode());
        self::assertSame('', (string) $delete->getBody());
    }

    public function testQueryParametersDtoAndScalars(): void
    {
        $kernel = $this->kernel();

        $dto = $kernel->handle(new ServerRequest('GET', '/api/users/search')->withQueryParams(['q' => 'php', 'page' => '2', 'exact' => '1']));
        self::assertSame(['q' => 'php', 'page' => 2, 'exact' => true], self::json($dto));

        $scalars = $kernel->handle(new ServerRequest('GET', '/api/users/page')->withQueryParams(['page' => '7']));
        self::assertSame(['page' => 7, 'sort' => null], self::json($scalars));

        $bad = $kernel->handle(new ServerRequest('GET', '/api/users/page')->withQueryParams(['page' => 'x']));
        self::assertSame(400, $bad->getStatusCode());
        self::assertSame(['page: must be an integer'], self::json($bad)['errors']);

        $missing = $kernel->handle(new ServerRequest('GET', '/api/users/search'));
        self::assertSame(400, $missing->getStatusCode());
    }

    public function testEnumPlaceholdersAndRouteMatchInjection(): void
    {
        $kernel = $this->kernel();

        self::assertSame(['format' => 'Xml'], self::json($kernel->handle(new ServerRequest('GET', '/api/users/format/Xml'))));
        self::assertSame(['role' => 'admin'], self::json($kernel->handle(new ServerRequest('GET', '/api/users/role/admin'))));
        self::assertSame(400, $kernel->handle(new ServerRequest('GET', '/api/users/role/root'))->getStatusCode());

        $post = $kernel->handle(new ServerRequest('GET', '/api/users/1/posts/my-post'));
        self::assertSame(['id' => 1, 'slug' => 'my-post', 'route' => 'users.post'], self::json($post));
    }

    public function testServicesAndRawResponses(): void
    {
        $kernel = $this->kernel();

        self::assertSame(['now' => 42], self::json($kernel->handle(new ServerRequest('GET', '/api/users/time'))));

        $html = $kernel->handle(new ServerRequest('GET', '/api/users/html'));
        self::assertSame('text/html; charset=utf-8', $html->getHeaderLine('Content-Type'));
        self::assertSame('<h1>Hi</h1>', (string) $html->getBody());

        $raw = $kernel->handle(new ServerRequest('GET', '/api/users/raw'));
        self::assertSame(418, $raw->getStatusCode());
        self::assertSame('yes', $raw->getHeaderLine('X-Raw'));
    }

    public function testRouteMiddlewareRunsAfterGlobalAndCanReject(): void
    {
        $kernel = $this->kernel();

        $denied = $kernel->handle(new ServerRequest('GET', '/api/users/secret'));
        self::assertSame(401, $denied->getStatusCode());
        self::assertSame('Token', $denied->getHeaderLine('WWW-Authenticate'));
        self::assertSame('Token required', self::json($denied)['detail']);

        $allowed = $kernel->handle(new ServerRequest('GET', '/api/users/secret', ['X-Token' => 'secret']));
        self::assertSame('top secret', (string) $allowed->getBody());
        self::assertSame(['method', 'class', 'global'], $allowed->getHeader('X-Trace'));
    }

    public function testNotFoundAndMethodNotAllowedAreProblemDetails(): void
    {
        $kernel = $this->kernel();

        $notFound = $kernel->handle(new ServerRequest('GET', '/nope'));
        self::assertSame(404, $notFound->getStatusCode());
        self::assertSame(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404, 'detail' => 'No route for GET /nope'], self::json($notFound));

        $notAllowed = $kernel->handle(new ServerRequest('PATCH', '/api/users/1'));
        self::assertSame(405, $notAllowed->getStatusCode());
        self::assertSame('GET, PUT, DELETE', $notAllowed->getHeaderLine('Allow'));

        $head = $kernel->handle(new ServerRequest('HEAD', '/api/users/1'));
        self::assertSame(200, $head->getStatusCode());
    }

    public function testHttpExceptionFromControllerKeepsMessage(): void
    {
        $response = $this->kernel()->handle(new ServerRequest('GET', '/api/users/forbidden'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('No access to this resource', self::json($response)['detail']);
        self::assertSame([], $this->logger->records, '4xx is not logged');
    }

    public function testUnexpectedExceptionIs500HiddenUnlessDebug(): void
    {
        $response = $this->kernel()->handle(new ServerRequest('GET', '/api/users/boom'));
        self::assertSame(500, $response->getStatusCode());
        $body = self::json($response);
        self::assertSame('Internal Server Error', $body['title']);
        self::assertArrayNotHasKey('detail', $body);
        self::assertArrayNotHasKey('exception', $body);
        self::assertSame(['error: Something broke'], $this->logger->records);

        $debug = self::json($this->kernel(debug: true)->handle(new ServerRequest('GET', '/api/users/boom')));
        self::assertSame('Something broke', $debug['detail']);
        self::assertIsArray($debug['exception']);
        self::assertSame(\RuntimeException::class, $debug['exception']['class']);
    }

    public function testBrowsersGetAnHtmlErrorPage(): void
    {
        $debug = $this->kernel(debug: true)->handle(new ServerRequest('GET', '/api/users/boom', ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8', 'Authorization' => 'Bearer s3cret']));
        self::assertSame(500, $debug->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $debug->getHeaderLine('Content-Type'));
        $html = (string) $debug->getBody();
        self::assertStringContainsString('<span>500</span> Internal Server Error', $html);
        self::assertStringContainsString('Something broke', $html);
        self::assertStringContainsString('RuntimeException', $html);
        self::assertStringContainsString('Copy as Markdown', $html);
        self::assertStringContainsString('## 500 Internal Server Error', $html, 'markdown export is embedded');
        self::assertStringNotContainsString('s3cret', $html, 'secrets are redacted');

        $prod = $this->kernel()->handle(new ServerRequest('GET', '/api/users/boom', ['Accept' => 'text/html']));
        self::assertSame('text/html; charset=utf-8', $prod->getHeaderLine('Content-Type'));
        self::assertStringNotContainsString('Something broke', (string) $prod->getBody());
        self::assertStringNotContainsString('Copy as Markdown', (string) $prod->getBody());

        $json = $this->kernel()->handle(new ServerRequest('GET', '/api/users/boom', ['Accept' => 'application/json, text/html']));
        self::assertSame('application/problem+json', $json->getHeaderLine('Content-Type'), 'json listed first wins');

        $notFound = $this->kernel()->handle(new ServerRequest('GET', '/nope', ['Accept' => 'text/html']));
        self::assertSame(404, $notFound->getStatusCode());
        self::assertStringContainsString('<span>404</span> Not Found', (string) $notFound->getBody());
    }

    public function testUnresolvableHandlerParameterIsConfigurationError(): void
    {
        $body = self::json($this->kernel(debug: true)->handle(new ServerRequest('GET', '/api/users/unresolvable')));

        self::assertSame(500, $body['status']);
        self::assertIsString($body['detail']);
        self::assertStringContainsString('Cannot resolve parameter $missing of ' . UserController::class . '::unresolvable', $body['detail']);
    }

    public function testMultipleMethodsAndRepeatedAttributes(): void
    {
        $kernel = $this->kernel();

        self::assertSame(['method' => 'POST'], self::json($kernel->handle(new ServerRequest('POST', '/api/users/multi'))));
        self::assertSame(['method' => 'GET'], self::json($kernel->handle(new ServerRequest('GET', '/api/users/multi-alias'))));
    }

    public function testTerminateResetsResettableServices(): void
    {
        $kernel = $this->kernel();
        $kernel->handle(new ServerRequest('GET', '/api/users/1'));
        $kernel->handle(new ServerRequest('GET', '/api/users/1'));
        $counter = $kernel->container->get(RequestCounter::class);
        self::assertSame(2, $counter->hits);

        $kernel->terminate();

        self::assertSame(0, $counter->hits);
        self::assertSame(1, $counter->resets);
        self::assertSame(['id' => 1, 'trace' => ['global', 'class'], 'hits' => 1], self::json($kernel->handle(new ServerRequest('GET', '/api/users/1'))));
    }
}
