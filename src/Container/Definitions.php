<?php

declare(strict_types=1);

namespace Keel\Container;

use Keel\Container\Exception\ContainerException;
use Keel\Container\Reflection\ClassAttributes;

/**
 * Registry of service definitions, parameters, aliases and pre-built instances.
 * Shared by the runtime container and the compiler.
 */
final class Definitions
{
    /** @var array<string, Definition> */
    private array $definitions = [];

    /** @var array<string, string> */
    private array $aliases = [];

    /** @var array<string, mixed> */
    private array $parameters = [];

    /** @var array<string, object> */
    private array $instances = [];

    public function load(ServiceProvider ...$providers): self
    {
        foreach ($providers as $provider) {
            $provider->register($this);
        }

        return $this;
    }

    /**
     * Registers a service. When $class (or $id) is an existing class, its container
     * attributes (Lazy, Transient, Alias, Tag) seed the definition.
     *
     * @param class-string|string      $id
     * @param class-string|string|null $class
     */
    public function set(string $id, ?string $class = null): Definition
    {
        $definition = new Definition($id, $class);
        if (class_exists($definition->class)) {
            ClassAttributes::apply(new \ReflectionClass($definition->class), $definition);
        }

        $this->definitions[$id] = $definition;

        return $definition;
    }

    /**
     * Registers a pre-built object. The compiler expects such objects to be passed
     * to the compiled container's constructor at runtime.
     */
    public function instance(string $id, object $instance): self
    {
        $this->instances[$id] = $instance;

        return $this;
    }

    /**
     * Registers a scalar/array parameter, retrievable with get($name) or #[Inject($name)].
     */
    public function parameter(string $name, mixed $value): self
    {
        $this->parameters[$name] = $value;

        return $this;
    }

    public function alias(string $alias, string $target): self
    {
        $this->aliases[$alias] = $target;

        return $this;
    }

    public function definition(string $id): ?Definition
    {
        return $this->definitions[$id] ?? null;
    }

    /** @return array<string, Definition> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /** @return array<string, mixed> */
    public function parameters(): array
    {
        return $this->parameters;
    }

    public function hasParameter(string $name): bool
    {
        return array_key_exists($name, $this->parameters);
    }

    /** @return array<string, object> */
    public function instances(): array
    {
        return $this->instances;
    }

    /**
     * Explicit aliases merged with aliases declared on definitions. Later registrations win.
     *
     * @return array<string, string>
     */
    public function aliases(): array
    {
        $aliases = [];
        foreach ($this->definitions as $definition) {
            foreach ($definition->aliases as $alias) {
                $aliases[$alias] = $definition->id;
            }
        }

        return $this->aliases + $aliases;
    }

    /**
     * Follows alias chains to the final id.
     */
    public function resolveAlias(string $id): string
    {
        $aliases = $this->aliases();
        $seen = [];
        while (isset($aliases[$id])) {
            if (isset($seen[$id])) {
                throw new ContainerException(sprintf('Alias loop detected at "%s"', $id));
            }
            $seen[$id] = true;
            $id = $aliases[$id];
        }

        return $id;
    }

    /**
     * Ids of services carrying the tag, in registration order.
     *
     * @return list<string>
     */
    public function tagged(string $tag): array
    {
        $ids = [];
        foreach ($this->definitions as $definition) {
            if (in_array($tag, $definition->tags, true)) {
                $ids[] = $definition->id;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, list<string>>
     */
    public function tags(): array
    {
        $tags = [];
        foreach ($this->definitions as $definition) {
            foreach ($definition->tags as $tag) {
                $tags[$tag][] = $definition->id;
            }
        }

        return $tags;
    }
}
