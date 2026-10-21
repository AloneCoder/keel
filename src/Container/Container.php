<?php

declare(strict_types=1);

namespace Keel\Container;

use Keel\Container\Exception\CircularDependencyException;
use Keel\Container\Exception\ContainerException;
use Keel\Container\Exception\NotFoundException;
use Keel\Container\Reflection\ClassAttributes;
use Keel\Container\Reflection\Instantiator;
use Psr\Container\ContainerInterface as PsrContainerInterface;

/**
 * Reflection-based container for development and tests. Production uses the compiled
 * container produced by Compiler, which behaves identically.
 *
 * Definitions are treated as frozen once the container is created. Lookup order, shared with
 * the compiled container: pre-built instances, definitions (registered or autowired), parameters.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, mixed> */
    private array $instances;

    /** @var array<string, true> */
    private array $resolving = [];

    /** @var array<string, Definition> */
    private array $autowired = [];

    /** @var array<string, string> alias => final id */
    private readonly array $aliases;

    private readonly Instantiator $instantiator;

    /**
     * @throws ContainerException on an alias loop or an alias colliding with a definition id
     */
    public function __construct(
        private readonly Definitions $definitions,
        private readonly bool $autowire = true,
        ?Instantiator $instantiator = null,
    ) {
        $this->instantiator = $instantiator ?? new Instantiator();
        $this->instances = $definitions->instances();
        // The container can inject itself under both the PSR and the Keel interface.
        $this->instances[PsrContainerInterface::class] = $this;
        $this->instances[ContainerInterface::class] = $this;

        $aliases = [];
        foreach ($definitions->aliases() as $alias => $target) {
            $aliases[$alias] = $definitions->resolveAlias($target);
        }
        $this->aliases = $aliases;
    }

    public function get(string $id): mixed
    {
        $id = $this->aliases[$id] ?? $id;

        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        $definition = $this->definitions->definition($id) ?? $this->autowired[$id] ?? null;
        if ($definition === null) {
            if ($this->definitions->hasParameter($id)) {
                return $this->definitions->parameters()[$id];
            }

            $definition = $this->autowiredDefinition($id) ?? throw $this->notFound($id);
        }

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
        $id = $this->aliases[$id] ?? $id;

        return array_key_exists($id, $this->instances)
            || $this->definitions->definition($id) !== null
            || isset($this->autowired[$id])
            || $this->definitions->hasParameter($id)
            || $this->autowiredDefinition($id) !== null;
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
        Resetter::reset($this->instances);
    }

    /**
     * PSR-11 reserves NotFoundExceptionInterface for the id that was asked for; a dependency
     * missing deeper in the chain is a plain ContainerException carrying the chain.
     */
    private function notFound(string $id): ContainerException
    {
        if ($this->resolving === []) {
            return NotFoundException::unresolvable($id, [], $this->autowire);
        }

        return ContainerException::unresolvable($id, array_keys($this->resolving), $this->autowire);
    }

    private function autowiredDefinition(string $id): ?Definition
    {
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
