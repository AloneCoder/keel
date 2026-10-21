<?php

declare(strict_types=1);

namespace Keel\Runtime;

use Keel\Kernel\Kernel;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Log\LoggerInterface;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use Spiral\RoadRunner\Worker;

/**
 * RoadRunner HTTP worker (spiral/roadrunner-http). Requests arrive as PSR-7 objects over
 * the Goridge pipe; the loop ends when RoadRunner stops the worker.
 */
final class RoadRunnerRuntime implements RuntimeInterface
{
    public function __construct(
        private readonly ?PSR7WorkerInterface $worker = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function run(Kernel $kernel): int
    {
        $worker = $this->worker ?? self::createWorker($kernel);

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
                return 0;
            }

            try {
                $worker->respond($kernel->handle($request));
            } catch (\Throwable $exception) {
                $this->logger?->critical($exception->getMessage(), ['exception' => $exception]);
                $worker->getWorker()->error((string) $exception);
            } finally {
                $kernel->terminate();
                gc_collect_cycles();
            }
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
