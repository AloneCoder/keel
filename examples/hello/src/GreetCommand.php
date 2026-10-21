<?php

declare(strict_types=1);

namespace Keel\Examples\Hello;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Discovered command: bin/console hello:greet Ann
 */
#[AsCommand(name: 'hello:greet', description: 'Greet someone from the command line')]
final class GreetCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'Who to greet', 'world');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $output->writeln('Hello, ' . (is_string($name) ? $name : 'world') . '!');

        return Command::SUCCESS;
    }
}
