<?php

declare(strict_types=1);

namespace Keel\Runtime;

use Keel\Http\EmitterInterface;
use Keel\Http\SapiEmitter;
use Keel\Kernel\Kernel;
use Nyholm\Psr7Server\ServerRequestCreatorInterface;

/**
 * Classic one-process-per-request model: php-fpm, mod_php, the built-in server and
 * FrankenPHP without worker mode.
 */
final class FpmRuntime implements RuntimeInterface
{
    public function __construct(private readonly ?EmitterInterface $emitter = null) {}

    public function run(Kernel $kernel): int
    {
        $creator = $kernel->container->get(ServerRequestCreatorInterface::class);
        $emitter = $this->emitter ?? new SapiEmitter();

        // terminate() runs even when request creation or emitting throws: Resettable services
        // must not keep this request's state, and the exception still reaches the SAPI.
        try {
            $emitter->emit($kernel->handle($creator->fromGlobals()));
        } finally {
            $kernel->terminate();
        }

        return 0;
    }
}
