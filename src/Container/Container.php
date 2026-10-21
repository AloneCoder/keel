<?php

declare(strict_types=1);

namespace Keel\Container;

use Keel\Container\Exception\CircularDependencyException;
use Keel\Container\Exception\NotFoundException;
use Keel\Container\Reflection\ClassAttributes;
use Keel\Container\Reflection\Instantiator;

/**
 * Reflection-based container for development and tests. Production uses the compiled
 * container produced by Compiler, which behaves identically.
 *
 * Definitions are treated as frozen once the container is created.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, mixed> */
    private array $instances;

    /** @var array<string, true> */
    private array $resolving = [];

    /** @var array<string, Definition> */
    private array $autowired = [];

    /** @var array<string, string>|null */
    private ?array $aliases = null;

    private readonly Instantiator $instantiator;

    public function __construct(
        private readonly Definitions $definitions,
        private readonly bool $autowire = true,
        ?Instantiator $instantiator = null,
    ) {
        $this->instantiator = $instantiator ?? new Instantiator();
        $this->instances = $definitions->instances();
    }

    public function get(string $id): mixed
    {
        $id = $this->resolveAlias($id);

        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if ($this->definitions->hasParameter($id)) {
            return $this->definitions->parameters()[$id];
        }

        $definition = $this->definitionFor($id)
            ?? throw NotFoundException::forId($id, array_keys($this->resolving));

        if (isset($this->resolving[$id])) {
            throw CircularDependencyException::forChain([...array_keys($this->resolving), $id]);
        }

        $this->resolving[$id] = true;
        try {
            $service = $this->instantiator->create($definition, $this);
        } finally {
            unset($this->resolving[$id]);
        }

        if ($definition->shared) {
            $this->instances[$id] = $service;
        }

        return $service;
    }

    public function has(string $id): bool
    {
        $id = $this->resolveAlias($id);

        return array_key_exists($id, $this->instances)
            || $this->definitions->hasParameter($id)
            || $this->definitionFor($id) !== null;
    }

    public function tagged(string $tag): array
    {
        $services = [];
        foreach ($this->definitions->tagged($tag) as $id) {
            $service = $this->get($id);
            if (is_object($service)) {
                $services[] = $service;
            }
        }

        return $services;
    }

    public function reset(): void
    {
        foreach ($this->instances as $instance) {
            if ($instance instanceof Resettable && !Instantiator::isUninitializedLazy($instance)) {
                $instance->reset();
            }
        }
    }

    private function resolveAlias(string $id): string
    {
        $this->aliases ??= $this->definitions->aliases();
        $seen = [];
        while (isset($this->aliases[$id])) {
            if (isset($seen[$id])) {
                return $id;
            }
            $seen[$id] = true;
            $id = $this->aliases[$id];
        }

        return $id;
    }

    private function definitionFor(string $id): ?Definition
    {
        $definition = $this->definitions->definition($id) ?? $this->autowired[$id] ?? null;
        if ($definition !== null) {
            return $definition;
        }

        if (!$this->autowire || !class_exists($id)) {
            return null;
        }

        $class = new \ReflectionClass($id);
        if (!$class->isInstantiable()) {
            return null;
        }

        $definition = new Definition($id);
        ClassAttributes::apply($class, $definition);

        return $this->autowired[$id] = $definition;
    }
}
