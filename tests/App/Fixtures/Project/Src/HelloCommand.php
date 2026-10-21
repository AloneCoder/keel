<?php

declare(strict_types=1);

namespace Keel\Tests\App\Fixtures\Project\Src;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'demo:hello', description: 'Prints the greeting prefix')]
final class HelloCommand extends Command
{
    public function __construct(private readonly GreetingConfig $config)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->config->prefix . ' from the command');

        return Command::SUCCESS;
    }
}
