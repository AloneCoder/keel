<?php

declare(strict_types=1);

namespace Keel\Container\Exception;

use Psr\Container\NotFoundExceptionInterface;

final class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
    /**
     * @param list<string> $chain
     */
    public static function forId(string $id, array $chain = []): self
    {
        return new self(self::describeChain(
            sprintf('Service "%s" is not registered and cannot be autowired', $id),
            $chain,
        ));
    }
}
