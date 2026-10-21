<?php

declare(strict_types=1);

namespace Keel\Container\Reflection;

/**
 * Where one constructor/factory argument comes from.
 */
final readonly class ArgumentPlan
{
    private function __construct(
        public string $name,
        public ArgumentSource $source,
        public mixed $value,
        public mixed $default = null,
    ) {}

    public static function service(string $name, string $id): self
    {
        return new self($name, ArgumentSource::Service, $id);
    }

    /**
     * Injects the service when the container has it, otherwise the default value.
     */
    public static function optionalService(string $name, string $id, mixed $default): self
    {
        return new self($name, ArgumentSource::OptionalService, $id, $default);
    }

    public static function tagged(string $name, string $tag): self
    {
        return new self($name, ArgumentSource::Tagged, $tag);
    }

    public static function value(string $name, mixed $value): self
    {
        return new self($name, ArgumentSource::Value, $value);
    }

    public function id(): string
    {
        if (!is_string($this->value)) {
            throw new \LogicException('Only service and tagged arguments carry an id');
        }

        return $this->value;
    }
}
