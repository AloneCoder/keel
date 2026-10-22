<?php

declare(strict_types=1);

namespace Keel\Tests\Runtime;

use Spiral\RoadRunner\Payload;
use Spiral\RoadRunner\WorkerInterface;

/**
 * The Goridge side of a RoadRunner worker: records every frame the PSR-7 layer sends.
 */
final class FakeRoadRunnerWorker implements WorkerInterface
{
    /** @var list<Payload> */
    public array $frames = [];

    /** @var list<string> */
    public array $errors = [];

    public bool $stopped = false;

    public function waitPayload(): ?Payload
    {
        return null;
    }

    public function respond(Payload $payload): void
    {
        $this->frames[] = $payload;
    }

    public function error(string $error): void
    {
        $this->errors[] = $error;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function hasPayload(?string $class = null): bool
    {
        return false;
    }

    public function getPayload(?string $class = null): ?Payload
    {
        return null;
    }
}
