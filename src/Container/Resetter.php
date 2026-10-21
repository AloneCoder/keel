<?php

declare(strict_types=1);

namespace Keel\Container;

use Keel\Container\Exception\ContainerException;
use Keel\Container\Reflection\Instantiator;

/**
 * Shared reset loop of both containers: every Resettable is visited even when one of them
 * throws, so a single failing service cannot leave the others holding request state.
 *
 * @internal
 */
final class Resetter
{
    /**
     * @param iterable<mixed> $instances
     *
     * @throws ContainerException when at least one reset() threw; the first failure is the previous exception
     */
    public static function reset(iterable $instances): void
    {
        $failures = [];
        foreach ($instances as $instance) {
            if (!$instance instanceof Resettable || Instantiator::isUninitializedLazy($instance)) {
                continue;
            }

            try {
                $instance->reset();
            } catch (\Throwable $exception) {
                $failures[] = $exception;
            }
        }

        if ($failures === []) {
            return;
        }

        $messages = array_map(
            static fn(\Throwable $failure): string => sprintf('%s (%s)', $failure->getMessage(), $failure::class),
            $failures,
        );

        throw new ContainerException(
            sprintf('%d service(s) failed to reset: %s', count($failures), implode('; ', $messages)),
            0,
            $failures[0],
        );
    }
}
