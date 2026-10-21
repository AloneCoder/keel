<?php

declare(strict_types=1);

namespace Keel\Container\Exception;

use Psr\Container\ContainerExceptionInterface;

class ContainerException extends \RuntimeException implements ContainerExceptionInterface
{
    /**
     * @param list<string> $chain
     */
    public static function withChain(string $message, array $chain): self
    {
        return new self(self::describeChain($message, $chain));
    }

    /**
     * @param list<string> $chain
     */
    protected static function describeChain(string $message, array $chain): string
    {
        if ($chain !== []) {
            $message .= ' (resolution chain: ' . implode(' -> ', $chain) . ')';
        }

        return $message;
    }
}
