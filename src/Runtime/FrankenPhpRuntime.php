<?php

declare(strict_types=1);

namespace Keel\Runtime;

use Keel\Http\EmitterInterface;
use Keel\Http\SapiEmitter;
use Keel\Kernel\Event\WorkerStarted;
use Keel\Kernel\Event\WorkerStopping;
use Keel\Kernel\Kernel;
use Nyholm\Psr7Server\ServerRequestCreatorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * FrankenPHP worker mode: the script boots once, then frankenphp_handle_request() hands
 * requests to the handler in a loop. Superglobals are refreshed for every request.
 * Each iteration ends, after the response went out, with Kernel::terminate() (ResponseSent,
 * deferred tasks, reset) and a garbage-collection run; MAX_REQUESTS (constructor argument or
 * $_SERVER['MAX_REQUESTS']) and the MemoryGuard (KEEL_MAX_MEMORY) restart the worker as safety
 * nets. WorkerStarted and WorkerStopping frame the loop.
 */
final class FrankenPhpRuntime implements RuntimeInterface
{
    public const string NAME = 'frankenphp';

    private bool $served = false;

    private ?ServerRequestInterface $request = null;

    private ?ResponseInterface $sent = null;

    /**
     * @param (\Closure(callable(): void): bool)|null $handleRequest replacement for frankenphp_handle_request(), used in tests
     */
    public function __construct(
        private readonly ?int $maxRequests = null,
        private readonly ?\Closure $handleRequest = null,
        private readonly ?EmitterInterface $emitter = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?MemoryGuard $memory = null,
    ) {}

    public function run(Kernel $kernel): int
    {
        $handleRequest = $this->handleRequest ?? self::nativeHandler();
        $creator = $kernel->container->get(ServerRequestCreatorInterface::class);
        $emitter = $this->emitter ?? new SapiEmitter();
        $configured = $_SERVER['MAX_REQUESTS'] ?? 0;
        $max = $this->maxRequests ?? (is_numeric($configured) ? (int) $configured : 0);

        $handler = function () use ($kernel, $creator, $emitter): void {
            $this->serve($kernel, $creator, $emitter);
        };

        $memory = $this->memory ?? MemoryGuard::fromEnvironment(null, $this->logger);
        $kernel->dispatch(new WorkerStarted(self::NAME));
        $handled = 0;
        $reason = WorkerStopping::REASON_SERVER;
        while (true) {
            if ($max > 0 && $handled >= $max) {
                $reason = WorkerStopping::REASON_MAX_REQUESTS;
                break;
            }
            $this->served = false;
            $this->request = $this->sent = null;
            $keepRunning = $handleRequest($handler);
            if ($this->served) {
                // After the handler returned the response is complete: work done here no longer delays it.
                $handled++;
                $kernel->terminate($this->request, $this->sent);
                $this->request = $this->sent = null;
                gc_collect_cycles();
            }
            if (!$keepRunning) {
                break;
            }
            if ($memory->exceeded($handled)) {
                $reason = WorkerStopping::REASON_MEMORY;
                break;
            }
        }
        $kernel->dispatch(new WorkerStopping(self::NAME, $handled, $reason));

        return 0;
    }

    /**
     * One request inside frankenphp_handle_request(): the request and the response that went out
     * are kept for Kernel::terminate(), which runs after the handler returned.
     */
    private function serve(Kernel $kernel, ServerRequestCreatorInterface $creator, EmitterInterface $emitter): void
    {
        $this->served = true;
        try {
            $this->request = $creator->fromGlobals();
            $response = $kernel->handle($this->request);
            $emitter->emit($response);
            $this->sent = $response;
        } catch (\Throwable $exception) {
            // Kernel::handle() answers every exception itself; this covers request creation, emitting
            // and whatever slips past the exception handler's own fallback.
            $this->logger?->critical($exception->getMessage(), ['exception' => $exception]);
            if (!headers_sent()) {
                http_response_code(500);
            }
        }
    }

    /**
     * @return \Closure(callable(): void): bool
     */
    private static function nativeHandler(): \Closure
    {
        if (!function_exists('frankenphp_handle_request')) {
            throw new \RuntimeException('FrankenPhpRuntime needs FrankenPHP in worker mode (frankenphp_handle_request is missing)');
        }

        /** @var \Closure(callable(): void): bool */
        return \Closure::fromCallable('frankenphp_handle_request');
    }
}
