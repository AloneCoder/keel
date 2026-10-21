<?php

declare(strict_types=1);

namespace Keel\Console\Command;

use Keel\App\App;
use Keel\Http\Responder;
use Keel\OpenApi\Generator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'keel:openapi', description: 'Print or write the OpenAPI 3.1 document of the application')]
final class OpenApiCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write the JSON to this file instead of stdout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = json_encode($this->app->container->get(Generator::class)->generate(), Responder::JSON_FLAGS | \JSON_PRETTY_PRINT) . "\n";

        $file = $input->getOption('output');
        if (is_string($file) && $file !== '') {
            $directory = dirname($file);
            if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Cannot create directory "%s"', $directory));
            }
            if (file_put_contents($file, $json) === false) {
                throw new \RuntimeException(sprintf('Cannot write "%s"', $file));
            }
            $output->writeln(sprintf('<info>written</info> %s', $file));

            return Command::SUCCESS;
        }

        $output->write($json);

        return Command::SUCCESS;
    }
}
