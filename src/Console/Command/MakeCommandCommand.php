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
        $short = self::studly((string) preg_replace('/^[^:]+:/', '', $name));
        if ($short === '' || !self::isClassName($short)) {
            return self::invalid($output, sprintf('"%s" does not yield a class name; use letters, e.g. app:import-users', $name));
        }
        $class = $short . 'Command';
        $literal = self::literal($name);
        $description = self::literal(sprintf('Describe what %s does', $name));
        $message = self::literal($name . ': not implemented yet');
        [$namespace] = $this->rootNamespace();

        $source = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace}\\Command;

            use Symfony\\Component\\Console\\Attribute\\AsCommand;
            use Symfony\\Component\\Console\\Command\\Command;
            use Symfony\\Component\\Console\\Input\\InputInterface;
            use Symfony\\Component\\Console\\Output\\OutputInterface;

            #[AsCommand(name: {$literal}, description: {$description})]
            final class {$class} extends Command
            {
                protected function execute(InputInterface \$input, OutputInterface \$output): int
                {
                    \$output->writeln({$message});

                    return Command::SUCCESS;
                }
            }

            PHP;

        return $this->write($input, $output, 'Command', $class, $source);
    }
}
