<?php

declare(strict_types=1);

namespace Keel\Runtime;

use Keel\Http\EmitterInterface;
use Keel\Http\SapiEmitter;
use Keel\Kernel\Kernel;
use Nyholm\Psr7Server\ServerRequestCreatorInterface;
use Psr\Log\LoggerInterface;

/**
 * FrankenPHP worker mode: the script boots once, then frankenphp_handle_request() hands
 * requests to the handler in a loop. Superglobals are refreshed for every request.
 * Each iteration ends with Kernel::terminate() and a garbage-collection run; MAX_REQUESTS
 * (constructor argument or $_SERVER['MAX_REQUESTS']) restarts the worker as a safety net.
 */
final class FrankenPhpRuntime implements RuntimeInterface
{
    /**
     * @param (\Closure(callable(): void): bool)|null $handleRequest replacement for frankenphp_handle_request(), used in tests
     */
    public function __construct(
        private readonly ?int $maxRequests = null,
        private readonly ?\Closure $handleRequest = null,
        private readonly ?EmitterInterface $emitter = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function run(Kernel $kernel): int
    {
        $handleRequest = $this->handleRequest ?? self::nativeHandler();
        $creator = $kernel->container->get(ServerRequestCreatorInterface::class);
        $emitter = $this->emitter ?? new SapiEmitter();
        $configured = $_SERVER['MAX_REQUESTS'] ?? 0;
        $max = $this->maxRequests ?? (is_numeric($configured) ? (int) $configured : 0);

        $handler = function () use ($kernel, $creator, $emitter): void {
            try {
                $emitter->emit($kernel->handle($creator->fromGlobals()));
            } catch (\Throwable $exception) {
                // Kernel::handle() answers every exception itself; this covers request creation, emitting
                // and whatever slips past the exception handler's own fallback.
                $this->logger?->critical($exception->getMessage(), ['exception' => $exception]);
                if (!headers_sent()) {
                    http_response_code(500);
                }
            } finally {
                $kernel->terminate();
            }
        };

        $handled = 0;
        while (($max <= 0 || $handled < $max) && $handleRequest($handler)) {
            $handled++;
            gc_collect_cycles();
        }

        return 0;
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
