<?php

declare(strict_types=1);

namespace Keel\Kernel;

use Keel\Container\Container;
use Keel\Container\ContainerInterface;
use Keel\Container\Definitions;
use Keel\Http\ArgumentResolver;
use Keel\Http\Pipeline;
use Keel\Http\Responder;
use Keel\Kernel\Event\ResponseSent;
use Keel\Routing\Redirector;
use Keel\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Nyholm\Psr7Server\ServerRequestCreatorInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HTTP kernel: exception handling -> global middleware -> routing -> route middleware -> controller.
 * One instance handles every request of a worker; terminate() dispatches ResponseSent, runs the
 * deferred tasks and resets per-request state.
 *
 * @phpstan-import-type MiddlewareEntry from \Keel\Routing\Attribute\MiddlewareProvider
 */
final class Kernel implements RequestHandlerInterface
{
    private readonly Pipeline $pipeline;

    private readonly Lifecycle $lifecycle;

    /**
     * @param list<MiddlewareInterface|MiddlewareEntry> $middleware global middleware, in order: instances, class names or [class, arguments]
     */
    public function __construct(
        public readonly ContainerInterface $container,
        public readonly Router $router,
        array $middleware = [],
        public readonly bool $debug = false,
    ) {
        $responder = $container->get(Responder::class);
        $this->lifecycle = new Lifecycle($container);

        $this->pipeline = new Pipeline(
            [
                new ExceptionHandlerMiddleware($responder, $debug, $container, $this->lifecycle),
                ...$middleware,
                new RoutingMiddleware($router),
            ],
            new DispatchHandler($container, $responder, $container->get(ArgumentResolver::class)),
            $container,
        );
    }

    /**
     * Builds a kernel on the reflection container: registers PSR-17 factories, the responder,
     * the argument resolver and the router, then wires everything.
     *
     * @param list<class-string>                        $controllers
     * @param list<MiddlewareInterface|MiddlewareEntry> $middleware
     */
    public static function build(
        Definitions $definitions,
        array $controllers,
        array $middleware = [],
        bool $debug = false,
        ?Router $router = null,
    ): self {
        $router ??= Router::fromControllers(...$controllers);
        self::registerDefaults($definitions);
        $definitions->instance(Router::class, $router);

        return new self(new Container($definitions), $router, $middleware, $debug);
    }

    /**
     * Registers what the kernel needs unless the application already did (as a definition, an alias
     * or an instance under the interface id): nyholm/psr7 as every PSR-17 factory, the server request
     * creator, Responder, ArgumentResolver, the Redirector behind #[Redirect] routes and DeferredTasks.
     */
    public static function registerDefaults(Definitions $definitions): void
    {
        $aliases = $definitions->aliases();
        $factories = [
            ResponseFactoryInterface::class,
            StreamFactoryInterface::class,
            ServerRequestFactoryInterface::class,
            RequestFactoryInterface::class,
            UriFactoryInterface::class,
            UploadedFileFactoryInterface::class,
        ];

        $instances = $definitions->instances();
        $bound = static fn(string $id): bool => $definitions->definition($id) !== null || isset($aliases[$id]) || isset($instances[$id]);

        $psr17 = $definitions->definition(Psr17Factory::class) ?? $definitions->set(Psr17Factory::class);
        foreach ($factories as $interface) {
            if (!$bound($interface)) {
                $psr17->alias($interface);
            }
        }

        if ($definitions->definition(ServerRequestCreator::class) === null && !$bound(ServerRequestCreatorInterface::class)) {
            $definitions->set(ServerRequestCreator::class)->alias(ServerRequestCreatorInterface::class);
        }
        if ($definitions->definition(Responder::class) === null) {
            $definitions->set(Responder::class);
        }
        if ($definitions->definition(ArgumentResolver::class) === null) {
            $definitions->set(ArgumentResolver::class);
        }
        if ($definitions->definition(Redirector::class) === null) {
            $definitions->set(Redirector::class);
        }
        if ($definitions->definition(DeferredTasks::class) === null) {
            $definitions->set(DeferredTasks::class);
        }
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->pipeline->handle($request);
    }

    /**
     * Called by the runtime after the response was sent: dispatches ResponseSent (when given the
     * request and its response), runs the DeferredTasks, then resets Resettable services. The
     * reset runs even when a step before it fails; listener and task failures are logged.
     */
    public function terminate(?ServerRequestInterface $request = null, ?ResponseInterface $response = null): void
    {
        try {
            if ($request !== null && $response !== null) {
                $this->lifecycle->notify(new ResponseSent($request, $response));
            }
            $deferred = $this->container->has(DeferredTasks::class) ? $this->container->get(DeferredTasks::class) : null;
            if ($deferred instanceof DeferredTasks && $deferred->pending() > 0) {
                $deferred->run($this->lifecycle->logger());
            }
        } finally {
            $this->container->reset();
        }
    }

    /**
     * Dispatches an event on the application's PSR-14 dispatcher when anyone listens to it; a
     * no-op without a dispatcher. The worker runtimes send WorkerStarted and WorkerStopping
     * through it. Listener exceptions propagate.
     */
    public function dispatch(object $event): void
    {
        $this->lifecycle->dispatch($event);
    }
}
