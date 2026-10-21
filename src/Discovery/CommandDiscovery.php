<?php

declare(strict_types=1);

namespace Keel\Discovery;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * Finds Symfony Console commands declared with #[AsCommand]. Only when symfony/console is installed.
 */
final class CommandDiscovery implements DiscoveryInterface
{
    /** @var array<string, class-string> command name => class */
    private array $commands = [];

    public function discover(\ReflectionClass $class): void
    {
        if (!class_exists(AsCommand::class) || !$class->isInstantiable() || !$class->isSubclassOf(Command::class)) {
            return;
        }

        foreach ($class->getAttributes(AsCommand::class) as $attribute) {
            $this->commands[$attribute->newInstance()->name] = $class->getName();
        }
    }

    /** @return array<string, class-string> */
    public function commands(): array
    {
        return $this->commands;
    }

    public function result(): array
    {
        return $this->commands;
    }

    public function restore(array $result): void
    {
        $this->commands = [];
        foreach ($result as $name => $class) {
            if (is_string($name) && is_string($class) && class_exists($class) && is_subclass_of($class, Command::class)) {
                $this->commands[$name] = $class;
            }
        }
    }
}
