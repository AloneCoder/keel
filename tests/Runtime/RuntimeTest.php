<?php

declare(strict_types=1);

namespace Keel\Tests\Runtime;

use Keel\Container\Definitions;
use Keel\Kernel\Kernel;
use Keel\Runtime\FpmRuntime;
use Keel\Runtime\FrankenPhpRuntime;
use Keel\Runtime\RoadRunnerRuntime;
use Keel\Runtime\Runtime;
use Keel\Tests\Kernel\Fixtures\ArrayLogger;
use Keel\Tests\Kernel\Fixtures\Clock;
use Keel\Tests\Kernel\Fixtures\RequestCounter;
use Keel\Tests\Kernel\Fixtures\UserController;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use Spiral\RoadRunner\WorkerInterface;

#[CoversClass(Runtime::class)]
#[CoversClass(FpmRuntime::class)]
#[CoversClass(FrankenPhpRuntime::class)]
#[CoversClass(RoadRunnerRuntime::class)]
final class RuntimeTest extends TestCase
{
    /** @var array<mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    private function kernel(): Kernel
    {
        $definitions = new Definitions();
        $definitions->set(Clock::class);
        $definitions->instance(LoggerInterface::class, new ArrayLogger());

        return Kernel::build($definitions, [UserController::class]);
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

    private static function globalsFor(string $method, string $uri): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
    }

    public function testDetectsRuntimeFromEnvironment(): void
    {
        self::assertInstanceOf(FpmRuntime::class, Runtime::detect([]));
        self::assertInstanceOf(FpmRuntime::class, Runtime::detect(['KEEL_RUNTIME' => 'fpm']));
        self::assertInstanceOf(FrankenPhpRuntime::class, Runtime::detect(['KEEL_RUNTIME' => 'FrankenPHP']));
        self::assertInstanceOf(RoadRunnerRuntime::class, Runtime::detect(['KEEL_RUNTIME' => 'roadrunner']));
        self::assertInstanceOf(RoadRunnerRuntime::class, Runtime::detect(['RR_MODE' => 'http']));
        self::assertInstanceOf(FpmRuntime::class, Runtime::detect(['FRANKENPHP_WORKER' => '1']), 'without the extension FrankenPHP worker mode is impossible');

        $this->expectException(\InvalidArgumentException::class);
        Runtime::detect(['KEEL_RUNTIME' => 'swoole']);
    }

    public function testFpmRuntimeHandlesOneRequestFromGlobalsAndTerminates(): void
    {
        self::globalsFor('GET', '/api/users/7');
        $kernel = $this->kernel();
        $emitter = new CollectingEmitter();

        $exit = new FpmRuntime($emitter)->run($kernel);

        self::assertSame(0, $exit);
        self::assertCount(1, $emitter->responses);
        self::assertSame(7, self::json($emitter->responses[0])['id']);
        self::assertSame(1, $kernel->container->get(RequestCounter::class)->resets, 'terminate() reset the counter');
    }

    public function testFrankenPhpLoopServesRequestsWithoutLeakingState(): void
    {
        $kernel = $this->kernel();
        $emitter = new CollectingEmitter();
        $uris = ['/api/users/1', '/api/users/2', '/nope', '/api/users/3'];
        $served = 0;
        $handleRequest = static function (callable $handler) use (&$served, $uris): bool {
            if (!isset($uris[$served])) {
                return false;
            }
            self::globalsFor('GET', $uris[$served++]);
            $handler();

            return true;
        };

        $exit = new FrankenPhpRuntime(null, $handleRequest, $emitter)->run($kernel);

        self::assertSame(0, $exit);
        self::assertSame(4, $served);
        self::assertCount(4, $emitter->responses);
        self::assertSame([200, 200, 404, 200], array_map(static fn(ResponseInterface $r): int => $r->getStatusCode(), $emitter->responses));
        // RequestCounter is Resettable: every request sees hits = 1, nothing carries over.
        self::assertSame(1, self::json($emitter->responses[0])['hits']);
        self::assertSame(1, self::json($emitter->responses[3])['hits']);
        self::assertSame(4, $kernel->container->get(RequestCounter::class)->resets);
    }

    public function testFrankenPhpLoopStopsAtMaxRequestsAndSurvivesEmitFailures(): void
    {
        $kernel = $this->kernel();
        $logger = $kernel->container->get(LoggerInterface::class);
        self::assertInstanceOf(ArrayLogger::class, $logger);
        $emitter = new CollectingEmitter();
        $calls = 0;
        $handleRequest = static function (callable $handler) use (&$calls): bool {
            $calls++;
            self::globalsFor('GET', '/api/users/' . $calls);
            $handler();

            return true; // FrankenPHP would keep going forever
        };
        $emitter->failWith = new \RuntimeException('socket closed');

        new FrankenPhpRuntime(3, $handleRequest, $emitter, $logger)->run($kernel);

        self::assertSame(3, $calls, 'loop ended because of MAX_REQUESTS');
        self::assertCount(2, $emitter->responses, 'the failed emit did not stop the worker');
        self::assertSame(['critical: socket closed'], $logger->records);
        self::assertSame(3, $kernel->container->get(RequestCounter::class)->resets, 'terminate() runs even when emitting fails');
    }

    public function testFrankenPhpRuntimeRequiresTheExtension(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('frankenphp_handle_request');
        new FrankenPhpRuntime()->run($this->kernel());
    }

    public function testRoadRunnerLoopRespondsUntilWorkerStops(): void
    {
        $kernel = $this->kernel();
        $worker = new class implements PSR7WorkerInterface {
            /** @var list<ServerRequestInterface|null> */
            public array $queue = [];

            /** @var list<ResponseInterface> */
            public array $responses = [];

            public function waitRequest(): ?ServerRequestInterface
            {
                return array_shift($this->queue);
            }

            public function respond(ResponseInterface $response): void
            {
                $this->responses[] = $response;
            }

            public function getWorker(): WorkerInterface
            {
                throw new \LogicException('not needed');
            }
        };
        $worker->queue = [new ServerRequest('GET', '/api/users/1'), new ServerRequest('DELETE', '/api/users/1'), null];

        $exit = new RoadRunnerRuntime($worker)->run($kernel);

        self::assertSame(0, $exit);
        self::assertSame([200, 204], array_map(static fn(ResponseInterface $r): int => $r->getStatusCode(), $worker->responses));
        self::assertSame(2, $kernel->container->get(RequestCounter::class)->resets);
    }
}
