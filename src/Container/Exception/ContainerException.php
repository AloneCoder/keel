<?php

declare(strict_types=1);

namespace Keel\Container\Exception;

use Psr\Container\ContainerExceptionInterface;

/**
 * @phpstan-consistent-constructor
 */
class ContainerException extends \RuntimeException implements ContainerExceptionInterface
{
    /** The id that could not be resolved, when the failure is a missing service. */
    public ?string $dependency = null;

    /** Why $dependency could not be built, in plain words. */
    public ?string $reason = null;

    /** Whether the container was autowiring when it failed; null when not applicable. */
    public ?bool $autowire = null;

    /** @var list<string> ids being resolved when the failure happened, outermost first */
    public array $chain = [];

    /**
     * @param list<string> $chain
     */
    public static function withChain(string $message, array $chain): static
    {
        $exception = new static(self::describeChain($message, $chain));
        $exception->chain = $chain;

        return $exception;
    }

    /**
     * A service that is neither registered nor autowirable. The message stays the one-line
     * summary; report() lays out dependency, reason, autowiring state and resolution path.
     *
     * @param list<string> $chain ids being resolved, outermost first (empty for a top-level get())
     */
    public static function unresolvable(string $id, array $chain, bool $autowire): static
    {
        $exception = static::withChain(sprintf('Service "%s" is not registered and cannot be autowired', $id), $chain);
        $exception->dependency = $id;
        $exception->reason = self::reasonFor($id, $autowire);
        $exception->autowire = $autowire;

        return $exception;
    }

    /**
     * Multi-line diagnosis for consoles and error pages; the plain message when the exception
     * carries no structure.
     */
    public function report(): string
    {
        if ($this->dependency === null) {
            return $this->getMessage();
        }

        $lines = [sprintf('Cannot resolve service %s', $this->chain === [] ? $this->dependency : end($this->chain)), ''];
        if ($this->chain !== []) {
            $lines[] = 'Dependency: ' . $this->dependency;
        }
        $lines[] = 'Reason: ' . $this->reason;
        if ($this->autowire !== null) {
            $lines[] = 'Autowiring: ' . ($this->autowire ? 'enabled' : 'disabled');
        }
        if ($this->chain !== []) {
            $lines[] = '';
            $lines[] = 'Resolution path:';
            $lines[] = implode(' -> ', [...$this->chain, $this->dependency]);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $chain
     */
    protected static function describeChain(string $message, array $chain): string
    {
        if ($chain !== []) {
            $message .= ' (resolution chain: ' . implode(' -> ', $chain) . ')';
        }

        return $message;
    }

    private static function reasonFor(string $id, bool $autowire): string
    {
        if (interface_exists($id)) {
            return 'it is an interface: register an implementation and alias the interface to it';
        }
        if (!class_exists($id)) {
            return 'no service or class has this id';
        }

        $class = new \ReflectionClass($id);
        if ($class->isAbstract()) {
            return 'it is an abstract class: register a concrete implementation and alias it';
        }
        if (!$class->isInstantiable()) {
            return 'its constructor is not public: register it with a factory';
        }

        return $autowire
            ? 'the class is not registered'
            : 'autowiring is disabled and the class is not registered';
    }
}
