<?php

declare(strict_types=1);

namespace Keel\Container;

/**
 * Mutable description of one service. Built fluently through Definitions::set().
 */
final class Definition
{
    /** @var class-string|string */
    public string $class;

    /**
     * Factory producing the service. Accepted forms:
     *  - \Closure or any callable (runtime container only);
     *  - 'Class::method' string or [Class::class, 'method'] for a static method;
     *  - [Class::class, 'method'] for an instance method; the object is taken from the container.
     *
     * @var \Closure|array{0: class-string|object, 1: string}|string|null
     */
    public \Closure|array|string|null $factory = null;

    /**
     * Explicit argument overrides by parameter name. Values are injected as is,
     * except Ref instances, which are resolved from the container.
     *
     * @var array<string, mixed>
     */
    public array $arguments = [];

    public bool $shared = true;

    public bool $lazy = false;

    /** @var list<string> */
    public array $tags = [];

    /** @var list<string> */
    public array $aliases = [];

    /**
     * @param class-string|string|null $class
     */
    public function __construct(public readonly string $id, ?string $class = null)
    {
        $this->class = $class ?? $id;
    }

    /**
     * @param class-string $class
     */
    public function class(string $class): self
    {
        $this->class = $class;

        return $this;
    }

    /**
     * @param \Closure|array{0: class-string|object, 1: string}|string $factory
     */
    public function factory(\Closure|array|string $factory): self
    {
        $this->factory = $factory;

        return $this;
    }

    public function argument(string $name, mixed $value): self
    {
        $this->arguments[$name] = $value;

        return $this;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function arguments(array $arguments): self
    {
        $this->arguments = $arguments + $this->arguments;

        return $this;
    }

    public function shared(bool $shared = true): self
    {
        $this->shared = $shared;

        return $this;
    }

    public function transient(): self
    {
        return $this->shared(false);
    }

    public function lazy(bool $lazy = true): self
    {
        $this->lazy = $lazy;

        return $this;
    }

    public function tag(string $tag): self
    {
        if (!in_array($tag, $this->tags, true)) {
            $this->tags[] = $tag;
        }

        return $this;
    }

    public function alias(string $alias): self
    {
        if (!in_array($alias, $this->aliases, true)) {
            $this->aliases[] = $alias;
        }

        return $this;
    }
}
