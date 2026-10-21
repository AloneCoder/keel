<?php

declare(strict_types=1);

namespace Keel\Container\Reflection;

use Keel\Container\Attribute\Inject;
use Keel\Container\Attribute\TaggedIterator;
use Keel\Container\Exception\ContainerException;
use Keel\Container\Ref;

/**
 * Turns reflection of a constructor or factory into a list of argument plans.
 * Used by the runtime instantiator and by the compiler, so both resolve identically.
 *
 * @internal
 */
final class Planner
{
    /**
     * @param class-string|string $class
     *
     * @return \ReflectionClass<object>
     */
    public function classOf(string $class): \ReflectionClass
    {
        if (!class_exists($class)) {
            throw new ContainerException(sprintf('Class "%s" does not exist', $class));
        }

        $reflection = new \ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new ContainerException(sprintf('Class "%s" is not instantiable', $class));
        }

        return $reflection;
    }

    /**
     * @param array<string, mixed> $overrides explicit arguments by parameter name
     * @param string               $context  human-readable owner, e.g. "App\Foo::__construct"
     *
     * @return list<ArgumentPlan>
     */
    public function parameters(\ReflectionFunctionAbstract $function, array $overrides, string $context): array
    {
        $plans = [];
        foreach ($function->getParameters() as $parameter) {
            $plans[] = $this->parameter($parameter, $overrides, $context);
        }

        return $plans;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function parameter(\ReflectionParameter $parameter, array $overrides, string $context): ArgumentPlan
    {
        $name = $parameter->getName();

        if (array_key_exists($name, $overrides)) {
            $value = $overrides[$name];

            return $value instanceof Ref
                ? ArgumentPlan::service($name, $value->id)
                : ArgumentPlan::value($name, $value);
        }

        $inject = $parameter->getAttributes(Inject::class);
        if ($inject !== []) {
            return ArgumentPlan::service($name, $inject[0]->newInstance()->id);
        }

        $tagged = $parameter->getAttributes(TaggedIterator::class);
        if ($tagged !== []) {
            return ArgumentPlan::tagged($name, $tagged[0]->newInstance()->tag);
        }

        $type = $parameter->getType();
        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            $class = $type->getName();
            if ($class === 'self' || $class === 'static') {
                $declaring = $parameter->getDeclaringClass();
                $class = $declaring !== null ? $declaring->getName() : $class;
            }

            if (!$parameter->isDefaultValueAvailable()) {
                return ArgumentPlan::service($name, $class);
            }

            $default = $parameter->getDefaultValue();
            if (is_object($default) && !$default instanceof \UnitEnum) {
                // "new Foo()" in the initializer: the class is autowirable, let the container build it.
                return ArgumentPlan::service($name, $class);
            }

            return ArgumentPlan::optionalService($name, $class, $default);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return ArgumentPlan::value($name, $parameter->getDefaultValue());
        }

        if ($type !== null && $type->allowsNull()) {
            return ArgumentPlan::value($name, null);
        }

        throw new ContainerException(sprintf(
            'Cannot resolve parameter $%s of %s: no type to autowire, no #[Inject], no default value',
            $name,
            $context,
        ));
    }
}
