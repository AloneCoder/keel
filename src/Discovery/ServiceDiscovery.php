<?php

declare(strict_types=1);

namespace Keel\Discovery;

use Keel\Container\Attribute\Alias;
use Keel\Container\Attribute\Lazy;
use Keel\Container\Attribute\Shared;
use Keel\Container\Attribute\Tag;
use Keel\Container\Attribute\Transient;
use Keel\Container\Definitions;

/**
 * Finds classes that must be registered explicitly for their container attributes to work:
 * #[Alias] and #[Tag] need a definition, autowiring alone would not see them.
 */
final class ServiceDiscovery implements DiscoveryInterface
{
    private const array ATTRIBUTES = [Alias::class, Tag::class, Lazy::class, Transient::class, Shared::class];

    /** @var list<class-string> */
    private array $services = [];

    public function discover(\ReflectionClass $class): void
    {
        if (!$class->isInstantiable()) {
            return;
        }

        foreach (self::ATTRIBUTES as $attribute) {
            if ($class->getAttributes($attribute) !== []) {
                $this->services[] = $class->getName();

                return;
            }
        }
    }

    public function register(Definitions $definitions): void
    {
        foreach ($this->services as $service) {
            if ($definitions->definition($service) === null) {
                $definitions->set($service);
            }
        }
    }

    /** @return list<class-string> */
    public function services(): array
    {
        return $this->services;
    }

    public function result(): array
    {
        return $this->services;
    }

    public function restore(array $result): void
    {
        $this->services = ClassScanner::classStrings($result);
    }
}
