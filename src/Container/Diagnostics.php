<?php

declare(strict_types=1);

namespace Keel\Container;

use Keel\Container\Exception\ContainerException;
use Keel\Container\Reflection\ArgumentSource;
use Keel\Container\Reflection\ClassAttributes;
use Keel\Container\Reflection\Instantiator;
use Keel\Container\Reflection\Planner;
use Psr\Container\ContainerInterface as PsrContainerInterface;

/**
 * Dry run of the container: walks every registered service, alias and tag and everything
 * reachable from them through constructor and factory parameters, and collects what could not
 * be resolved, with the same exceptions (dependency, reason, resolution path) the container
 * would throw at runtime. Nothing is instantiated, so it is safe for keel:doctor and CI.
 */
final class Diagnostics
{
    private readonly Planner $planner;

    /** @var array<string, true> */
    private array $visited = [];

    /** @var list<ContainerException> */
    private array $problems = [];

    public function __construct(
        private readonly Definitions $definitions,
        private readonly bool $autowire = true,
        ?Planner $planner = null,
    ) {
        $this->planner = $planner ?? new Planner();
    }

    /**
     * @return list<ContainerException> empty when every service resolves
     */
    public function check(): array
    {
        $this->visited = [];
        $this->problems = [];

        foreach (array_keys($this->definitions->definitions()) as $id) {
            $this->visit($id, []);
        }
        foreach ($this->definitions->aliases() as $alias => $target) {
            $this->visit($target, [$alias]);
        }
        foreach ($this->definitions->tags() as $ids) {
            foreach ($ids as $id) {
                $this->visit($id, []);
            }
        }

        return $this->problems;
    }

    /**
     * @param list<string> $chain
     */
    private function visit(string $id, array $chain): void
    {
        try {
            $id = $this->definitions->resolveAlias($id);
        } catch (ContainerException $exception) {
            $this->report($exception, $chain);

            return;
        }

        if (isset($this->visited[$id])) {
            return;
        }
        $this->visited[$id] = true;

        if (self::builtIn($id) || isset($this->definitions->instances()[$id]) || $this->definitions->hasParameter($id)) {
            return;
        }

        $definition = $this->definitions->definition($id) ?? $this->autowired($id);
        if ($definition === null) {
            $this->problems[] = ContainerException::unresolvable($id, $chain, $this->autowire);

            return;
        }

        $next = [...$chain, $id];
        try {
            [$plans, $owner] = $this->plans($definition);
        } catch (ContainerException $exception) {
            $this->report($exception, $next);

            return;
        }

        if ($owner !== null) {
            $this->visit($owner, $next);
        }
        foreach ($plans as $plan) {
            match ($plan->source) {
                ArgumentSource::Service => $this->visit($plan->id(), $next),
                ArgumentSource::OptionalService => $this->known($plan->id()) ? $this->visit($plan->id(), $next) : null,
                ArgumentSource::Tagged => array_map(fn(string $tagged) => $this->visit($tagged, $next), $this->definitions->tagged($plan->id())),
                ArgumentSource::Value => null,
            };
        }
    }

    /**
     * @return array{0: list<\Keel\Container\Reflection\ArgumentPlan>, 1: string|null} plans and the owner class of an instance-method factory
     */
    private function plans(Definition $definition): array
    {
        if ($definition->factory !== null) {
            [$function, $owner] = Instantiator::factoryFunction($definition);

            return [$this->planner->parameters($function, $definition->arguments, 'factory of ' . $definition->id), $owner];
        }

        $constructor = $this->planner->classOf($definition->class)->getConstructor();

        return [
            $constructor === null ? [] : $this->planner->parameters($constructor, $definition->arguments, $definition->class . '::__construct'),
            null,
        ];
    }

    /**
     * What has() would answer: an optional dependency is only followed when the container knows it.
     */
    private function known(string $id): bool
    {
        try {
            $id = $this->definitions->resolveAlias($id);
        } catch (ContainerException) {
            return true; // let visit() report the alias loop
        }

        return self::builtIn($id)
            || isset($this->definitions->instances()[$id])
            || $this->definitions->hasParameter($id)
            || $this->definitions->definition($id) !== null
            || $this->autowired($id) !== null;
    }

    /**
     * Both containers inject themselves under the PSR and the Keel interface.
     */
    private static function builtIn(string $id): bool
    {
        return $id === PsrContainerInterface::class || $id === ContainerInterface::class;
    }

    private function autowired(string $id): ?Definition
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

        return $definition;
    }

    /**
     * @param list<string> $chain
     */
    private function report(ContainerException $exception, array $chain): void
    {
        if ($exception->chain === [] && $chain !== []) {
            $exception->chain = $chain;
        }
        foreach ($this->problems as $known) {
            if ($known->getMessage() === $exception->getMessage()) {
                return; // the same problem reached through another path
            }
        }
        $this->problems[] = $exception;
    }
}
