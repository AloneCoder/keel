<?php

declare(strict_types=1);

namespace Keel\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'keel:make:command', description: 'Generate a console command discovered by #[AsCommand]')]
final class MakeCommandCommand extends MakeCommandBase
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('name', InputArgument::REQUIRED, 'Command name, e.g. app:import-users');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $name = is_string($name) && $name !== '' ? $name : 'app:task';
        $class = self::studly((string) preg_replace('/^[^:]+:/', '', $name)) . 'Command';
        [$namespace] = $this->rootNamespace();

        $source = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace}\\Command;

            use Symfony\\Component\\Console\\Attribute\\AsCommand;
            use Symfony\\Component\\Console\\Command\\Command;
            use Symfony\\Component\\Console\\Input\\InputInterface;
            use Symfony\\Component\\Console\\Output\\OutputInterface;

            #[AsCommand(name: '{$name}', description: 'Describe what {$name} does')]
            final class {$class} extends Command
            {
                protected function execute(InputInterface \$input, OutputInterface \$output): int
                {
                    \$output->writeln('{$name}: not implemented yet');

                    return Command::SUCCESS;
                }
            }

            PHP;

        return $this->write($input, $output, 'Command', $class, $source);
    }
}
