<?php

declare(strict_types=1);

namespace Keel\Tests\Runtime;

use Keel\Container\Definitions;
use Keel\Container\Exception\ContainerException;
use Keel\Http\SapiEmitter;
use Keel\Kernel\Kernel;
use Keel\Runtime\FpmRuntime;
use Keel\Runtime\FrankenPhpRuntime;
use Keel\Runtime\MemoryGuard;
use Keel\Runtime\RoadRunnerRuntime;
use Keel\Runtime\Runtime;
use Keel\Tests\Container\Fixtures\ExplodingReset;
use Keel\Tests\Kernel\Fixtures\ArrayLogger;
use Keel\Tests\Kernel\Fixtures\Clock;
use Keel\Tests\Kernel\Fixtures\RequestCounter;
use Keel\Tests\Kernel\Fixtures\UserController;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7Server\ServerRequestCreatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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
#[CoversClass(MemoryGuard::class)]
#[CoversClass(SapiEmitter::class)]
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

    /**
     * In a child process nothing has been written yet, so headers_sent() is false and the body can
     * be captured with output buffering. header() is a no-op under the CLI SAPI, so only the body
     * path (rewind, chunked read) is observable here.
     */
    #[RunInSeparateProcess]
    public function testSapiEmitterRewindsAndStreamsTheBody(): void
    {
        $factory = new Psr17Factory();
        $body = $factory->createStream('0123456789end');
        $body->seek(5);
        $response = $factory->createResponse(201)->withHeader('X-Test', 'yes')->withBody($body);

        ob_start();
        try {
            new SapiEmitter(4)->emit($response);
        } finally {
            $output = ob_get_clean();
        }

        self::assertSame('0123456789end', $output, 'the stream is rewound and read to the end in chunks');
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


    public function testFpmRuntimeTerminatesWhenEmittingOrRequestCreationThrows(): void
    {
        self::globalsFor('GET', '/api/users/7');
        $kernel = $this->kernel();
        $emitter = new CollectingEmitter();
        $emitter->failWith = new \RuntimeException('socket closed');

        try {
            new FpmRuntime($emitter)->run($kernel);
            self::fail('the emit failure must reach the SAPI');
        } catch (\RuntimeException $e) {
            self::assertSame('socket closed', $e->getMessage());
        }
        self::assertSame(1, $kernel->container->get(RequestCounter::class)->resets, 'terminate() ran although emit() threw');

        $creator = $this->createStub(ServerRequestCreatorInterface::class);
        $creator->method('fromGlobals')->willThrowException(new \LogicException('bad globals'));
        $definitions = new Definitions();
        $definitions->set(Clock::class);
        $definitions->instance(LoggerInterface::class, new ArrayLogger());
        $definitions->instance(ServerRequestCreatorInterface::class, $creator);
        $kernel = Kernel::build($definitions, [UserController::class]);
        $kernel->container->get(RequestCounter::class);

        try {
            new FpmRuntime(new CollectingEmitter())->run($kernel);
            self::fail('the request creation failure must reach the SAPI');
        } catch (\LogicException $e) {
            self::assertSame('bad globals', $e->getMessage());
        }
        self::assertSame(1, $kernel->container->get(RequestCounter::class)->resets, 'terminate() ran although no request was handled');
    }

    public function testFpmRuntimeReportsAResetFailureWithTheRequestFailureInItsChain(): void
    {
        self::globalsFor('GET', '/api/users/7');
        $definitions = new Definitions();
        $definitions->set(Clock::class);
        $definitions->instance(LoggerInterface::class, new ArrayLogger());
        $definitions->set(ExplodingReset::class);
        $kernel = Kernel::build($definitions, [UserController::class]);
        $kernel->container->get(ExplodingReset::class);
        $emitter = new CollectingEmitter();
        $emitter->failWith = new \RuntimeException('socket closed');

        try {
            new FpmRuntime($emitter)->run($kernel);
            self::fail('expected the reset failure');
        } catch (ContainerException $e) {
            // PHP appends the pending exception to the chain of the one thrown in the finally block.
            $chain = [];
            for ($link = $e; $link !== null; $link = $link->getPrevious()) {
                $chain[] = $link->getMessage();
            }
            self::assertSame(['1 service(s) failed to reset: boom (RuntimeException)', 'boom', 'socket closed'], $chain);
        }
    }


    public function testMemoryGuardReadsTheEnvironmentAndPhpIniShorthand(): void
    {
        self::assertSame(268_435_456, MemoryGuard::parseSize('256M'));
        self::assertSame(1_073_741_824, MemoryGuard::parseSize(' 1g '));
        self::assertSame(65_536, MemoryGuard::parseSize('64K'));
        self::assertSame(262_144, MemoryGuard::parseSize('262144'));
        self::assertSame(0, MemoryGuard::parseSize('-1'), 'unlimited');
        self::assertSame(0, MemoryGuard::parseSize('lots'), 'unparsable means off');

        self::assertSame(268_435_456, MemoryGuard::fromEnvironment(['KEEL_MAX_MEMORY' => '256M'])->limit());
        self::assertFalse(MemoryGuard::fromEnvironment(['KEEL_MAX_MEMORY' => '0'])->enabled());

        $memoryLimit = MemoryGuard::parseSize((string) ini_get('memory_limit'));
        $default = MemoryGuard::fromEnvironment(['KEEL_MAX_MEMORY' => ''])->limit();
        if ($memoryLimit > 0) {
            self::assertSame((int) ($memoryLimit * 0.9), $default, 'default: 90% of memory_limit');
        } else {
            self::assertSame(0, $default, 'memory_limit=-1: off');
        }

        $guard = new MemoryGuard(\PHP_INT_MAX);
        self::assertFalse($guard->exceeded(1));
        self::assertGreaterThan(0, $guard->usage());
        self::assertSame('1.5 MB', MemoryGuard::format(1_572_864));
        self::assertSame('512 B', MemoryGuard::format(512));
    }

    public function testFrankenPhpLoopStopsWhenTheMemoryGuardTrips(): void
    {
        $kernel = $this->kernel();
        $logger = new ArrayLogger();
        $emitter = new CollectingEmitter();
        $calls = 0;
        $handleRequest = static function (callable $handler) use (&$calls): bool {
            $calls++;
            self::globalsFor('GET', '/api/users/' . $calls);
            $handler();

            return true; // FrankenPHP would keep going forever
        };

        new FrankenPhpRuntime(null, $handleRequest, $emitter, $logger, new MemoryGuard(1, $logger))->run($kernel);

        self::assertSame(1, $calls, 'the loop ended right after the first request');
        self::assertCount(1, $logger->records);
        self::assertStringStartsWith('warning: Worker memory ', $logger->records[0]);
        self::assertStringContainsString('exceeds the limit of 1 B after 1 request(s)', $logger->records[0]);

        $calls = 0;
        new FrankenPhpRuntime(2, $handleRequest, $emitter, $logger, new MemoryGuard(0, $logger))->run($kernel);
        self::assertSame(2, $calls, 'a disabled guard never ends the loop');
        self::assertCount(1, $logger->records);
    }

    public function testRoadRunnerLoopAsksToBeStoppedWhenTheMemoryGuardTrips(): void
    {
        $kernel = $this->kernel();
        $logger = new ArrayLogger();
        $inner = $this->createMock(WorkerInterface::class);
        $inner->expects(self::once())->method('stop');
        $worker = $this->createStub(PSR7WorkerInterface::class);
        $worker->method('waitRequest')->willReturn(new ServerRequest('GET', '/api/users/1'));
        $worker->method('getWorker')->willReturn($inner);

        $exit = new RoadRunnerRuntime($worker, $logger, new MemoryGuard(1, $logger))->run($kernel);

        self::assertSame(0, $exit);
        self::assertSame(1, $kernel->container->get(RequestCounter::class)->resets, 'one request was served, then the worker asked to stop');
        self::assertCount(1, $logger->records);
        self::assertStringStartsWith('warning: Worker memory ', $logger->records[0]);
    }
}
