<?php

declare(strict_types=1);

namespace Keel\Container\Reflection;

use Keel\Container\Attribute\Alias;
use Keel\Container\Attribute\Lazy;
use Keel\Container\Attribute\Shared;
use Keel\Container\Attribute\Tag;
use Keel\Container\Attribute\Transient;
use Keel\Container\Definition;

/**
 * Copies class-level container attributes onto a definition.
 * Explicit fluent calls made afterwards take precedence.
 */
final class ClassAttributes
{
    /**
     * @param \ReflectionClass<object> $class
     */
    public static function apply(\ReflectionClass $class, Definition $definition): void
    {
        if ($class->getAttributes(Lazy::class) !== []) {
            $definition->lazy();
        }

        if ($class->getAttributes(Transient::class) !== []) {
            $definition->transient();
        } elseif ($class->getAttributes(Shared::class) !== []) {
            $definition->shared();
        }

        foreach ($class->getAttributes(Alias::class) as $attribute) {
            $definition->alias($attribute->newInstance()->id);
        }

        foreach ($class->getAttributes(Tag::class) as $attribute) {
            $definition->tag($attribute->newInstance()->name);
        }
    }
}
