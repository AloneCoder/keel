<?php

declare(strict_types=1);

namespace Keel\Console\Command;

use Keel\App\App;
use Keel\Console\Ai\AgentsGuide;
use Keel\Console\Ai\LlmsTxt;
use Keel\OpenApi\Generator;
use Keel\OpenApi\OpenApiConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'keel:ai:init', description: 'Write AGENTS.md (guidance for coding agents) and public/llms.txt (the API for language models)')]
final class AiInitCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite files that exist')
            ->addOption('agents', null, InputOption::VALUE_REQUIRED, 'Where to write the agent guide, relative to the project', 'AGENTS.md')
            ->addOption('llms', null, InputOption::VALUE_REQUIRED, 'Where to write llms.txt, relative to the project; "none" skips it', 'public/llms.txt')
            ->addOption('stdout', null, InputOption::VALUE_NONE, 'Print instead of writing files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $files = [];
        $agents = $input->getOption('agents');
        if (is_string($agents) && $agents !== 'none') {
            $files[$agents] = new AgentsGuide($this->app)->render();
        }
        $llms = $input->getOption('llms');
        if (is_string($llms) && $llms !== 'none') {
            $config = $this->app->configs[OpenApiConfig::class] ?? null;
            $files[$llms] = LlmsTxt::render($this->app->container->get(Generator::class)->generate(), $config instanceof OpenApiConfig ? $config : new OpenApiConfig());
        }

        if ($input->getOption('stdout') === true) {
            foreach ($files as $path => $content) {
                $output->writeln(sprintf('<comment>== %s</comment>', $path));
                $output->write($content, false, OutputInterface::OUTPUT_RAW);
            }

            return Command::SUCCESS;
        }

        $status = Command::SUCCESS;
        foreach ($files as $relative => $content) {
            $path = $this->app->projectDir . '/' . ltrim($relative, '/');
            if (is_file($path) && $input->getOption('force') !== true) {
                $output->writeln(sprintf('<error>%s exists; use --force to overwrite</error>', $relative));
                $status = Command::FAILURE;
                continue;
            }
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Cannot create directory "%s"', $directory));
            }
            if (file_put_contents($path, $content) === false) {
                throw new \RuntimeException(sprintf('Cannot write "%s"', $path));
            }
            $output->writeln('<info>wrote</info> ' . $relative);
        }

        return $status;
    }
}
