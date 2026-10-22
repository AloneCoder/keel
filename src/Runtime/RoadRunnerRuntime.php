<?php

declare(strict_types=1);

namespace Keel\Runtime;

use Keel\Kernel\Event\WorkerStarted;
use Keel\Kernel\Event\WorkerStopping;
use Keel\Kernel\Kernel;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Log\LoggerInterface;
use Spiral\RoadRunner\Http\Exception\StreamStoppedException;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use Spiral\RoadRunner\Payload;
use Spiral\RoadRunner\StreamWorkerInterface;
use Spiral\RoadRunner\Worker;

/**
 * RoadRunner HTTP worker (spiral/roadrunner-http). Requests arrive as PSR-7 objects over
 * the Goridge pipe; the loop ends when RoadRunner stops the worker, or when the MemoryGuard
 * (KEEL_MAX_MEMORY) says the process has grown too large: the worker then asks to be stopped
 * and RoadRunner starts a fresh one. WorkerStarted and WorkerStopping frame the loop.
 *
 * A non-seekable body (Responder::stream(), Server-Sent Events) is sent as a RoadRunner stream,
 * chunk by chunk (RoadRunner 2023.3 or newer); every other body goes out in one frame.
 */
final class RoadRunnerRuntime implements RuntimeInterface
{
    public const string NAME = 'roadrunner';

    private const int STREAM_CHUNK = 8192;

    public function __construct(
        private readonly ?PSR7WorkerInterface $worker = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?MemoryGuard $memory = null,
    ) {}

    public function run(Kernel $kernel): int
    {
        $worker = $this->worker ?? self::createWorker($kernel);
        $memory = $this->memory ?? MemoryGuard::fromEnvironment(null, $this->logger);
        $handled = 0;
        $reason = WorkerStopping::REASON_SERVER;
        $kernel->dispatch(new WorkerStarted(self::NAME));

        while (true) {
            try {
                $request = $worker->waitRequest();
            } catch (\Throwable $exception) {
                // Malformed payload: report to RoadRunner and keep serving.
                $this->logger?->error($exception->getMessage(), ['exception' => $exception]);
                $worker->getWorker()->error((string) $exception);
                continue;
            }

            if ($request === null) {
                break;
            }

            $sent = null;
            try {
                $response = $kernel->handle($request);
                $this->respond($worker, $response);
                $sent = $response;
            } catch (StreamStoppedException) {
                // The client closed a streamed response; RoadRunner waits for an empty frame confirming the stop.
                $this->logger?->info('Client closed a streamed response');
                $stream = $worker->getWorker();
                ($stream instanceof StreamWorkerInterface ? $stream->withStreamMode() : $stream)->respond(new Payload(''));
            } catch (\Throwable $exception) {
                $this->logger?->critical($exception->getMessage(), ['exception' => $exception]);
                $worker->getWorker()->error((string) $exception);
            } finally {
                $kernel->terminate($request, $sent);
                gc_collect_cycles();
            }

            if ($memory->exceeded(++$handled)) {
                $worker->getWorker()->stop();
                $reason = WorkerStopping::REASON_MEMORY;
                break;
            }
        }
        $kernel->dispatch(new WorkerStopping(self::NAME, $handled, $reason));

        return 0;
    }

    /**
     * PSR7Worker streams a body only while its chunkSize is set; set it for non-seekable bodies alone.
     */
    private function respond(PSR7WorkerInterface $worker, ResponseInterface $response): void
    {
        if (!$worker instanceof PSR7Worker || $response->getBody()->isSeekable()) {
            $worker->respond($response);

            return;
        }

        $chunkSize = $worker->chunkSize;
        $worker->chunkSize = self::STREAM_CHUNK;
        try {
            $worker->respond($response);
        } finally {
            $worker->chunkSize = $chunkSize;
            $response->getBody()->close();
        }
    }

    private static function createWorker(Kernel $kernel): PSR7WorkerInterface
    {
        if (!class_exists(PSR7Worker::class)) {
            throw new \RuntimeException('RoadRunnerRuntime needs spiral/roadrunner-http: composer require spiral/roadrunner-http');
        }

        return new PSR7Worker(
            Worker::create(),
            $kernel->container->get(ServerRequestFactoryInterface::class),
            $kernel->container->get(StreamFactoryInterface::class),
            $kernel->container->get(UploadedFileFactoryInterface::class),
        );
    }
}
