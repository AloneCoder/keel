<?php

declare(strict_types=1);

namespace Keel\Console\Command;

use Keel\App\App;
use Keel\App\AppBuilder;
use Keel\Container\Definition;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'keel:debug:container', description: 'List services, aliases, parameters and instances the container is built from')]
final class DebugContainerCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('filter', InputArgument::OPTIONAL, 'Only ids containing this text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filter = $input->getArgument('filter');
        $filter = is_string($filter) ? strtolower($filter) : '';
        $definitions = AppBuilder::definitionsFor($this->app);
        $aliases = $definitions->aliases();

        $rows = [];
        foreach ($definitions->definitions() as $definition) {
            if (!self::matches($filter, $definition->id, $definition->class)) {
                continue;
            }
            $rows[] = [$definition->id, $definition->class === $definition->id ? '' : $definition->class, self::scope($definition), self::describeFactory($definition), implode(', ', $definition->tags)];
        }
        foreach ($definitions->instances() as $id => $instance) {
            if (self::matches($filter, $id, $instance::class)) {
                $rows[] = [$id, $instance::class === $id ? '' : $instance::class, 'instance', '', ''];
            }
        }
        foreach ($definitions->parameters() as $name => $value) {
            if (self::matches($filter, $name, '')) {
                $rows[] = [$name, get_debug_type($value), 'parameter', '', ''];
            }
        }
        foreach ($aliases as $alias => $target) {
            if (self::matches($filter, $alias, $target)) {
                $rows[] = [$alias, '-> ' . $target, 'alias', '', ''];
            }
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));

        new Table($output)->setHeaders(['Id', 'Class / target', 'Scope', 'Factory', 'Tags'])->setRows($rows)->render();
        $output->writeln(sprintf('%d entries. Classes not listed are autowired on demand.', count($rows)));

        return Command::SUCCESS;
    }

    private static function matches(string $filter, string $id, string $class): bool
    {
        return $filter === '' || str_contains(strtolower($id), $filter) || str_contains(strtolower($class), $filter);
    }

    private static function scope(Definition $definition): string
    {
        return ($definition->shared ? 'shared' : 'transient') . ($definition->lazy ? ', lazy' : '');
    }

    private static function describeFactory(Definition $definition): string
    {
        $factory = $definition->factory;

        return match (true) {
            $factory === null => '',
            $factory instanceof \Closure => 'closure',
            is_string($factory) => $factory,
            is_object($factory[0]) => $factory[0]::class . '->' . $factory[1],
            default => $factory[0] . '::' . $factory[1],
        };
    }
}
