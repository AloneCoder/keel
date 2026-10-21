<?php

declare(strict_types=1);

namespace Keel\Discovery;

use Keel\Routing\Attribute\Route;

/**
 * Finds controllers: instantiable classes with at least one method carrying a Route attribute.
 */
final class RouteDiscovery implements DiscoveryInterface
{
    /** @var list<class-string> */
    private array $controllers = [];

    public function discover(\ReflectionClass $class): void
    {
        if (!$class->isInstantiable()) {
            return;
        }

        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF) !== []) {
                $this->controllers[] = $class->getName();

                return;
            }
        }
    }

    /** @return list<class-string> */
    public function controllers(): array
    {
        return $this->controllers;
    }

    public function result(): array
    {
        return $this->controllers;
    }

    public function restore(array $result): void
    {
        $this->controllers = ClassScanner::classStrings($result);
    }
}
