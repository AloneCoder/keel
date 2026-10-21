<?php

declare(strict_types=1);

namespace Keel\Tests\Runtime;

use Keel\Http\EmitterInterface;
use Psr\Http\Message\ResponseInterface;

final class CollectingEmitter implements EmitterInterface
{
    /** @var list<ResponseInterface> */
    public array $responses = [];

    public ?\Throwable $failWith = null;

    public function emit(ResponseInterface $response): void
    {
        if ($this->failWith !== null) {
            $failure = $this->failWith;
            $this->failWith = null;
            throw $failure;
        }

        $this->responses[] = $response;
    }
}
