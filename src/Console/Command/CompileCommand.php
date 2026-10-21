<?php

declare(strict_types=1);

namespace Keel\Console\Command;

use Keel\App\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'keel:compile', description: 'Rebuild the discovery, route and container caches')]
final class CompileCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->app->compile() as $path) {
            $output->writeln('<info>written</info> ' . $path);
        }

        return Command::SUCCESS;
    }
}
