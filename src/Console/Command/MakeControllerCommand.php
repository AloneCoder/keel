<?php

declare(strict_types=1);

namespace Keel\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'keel:make:controller', description: 'Generate a controller with a sample route')]
final class MakeControllerCommand extends MakeCommandBase
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('name', InputArgument::REQUIRED, 'Controller name, e.g. User or UserController');
        $this->addOption('prefix', 'p', InputOption::VALUE_REQUIRED, 'Path prefix, e.g. /api/users');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $name = self::studly(is_string($name) ? $name : 'Home');
        $class = str_ends_with($name, 'Controller') ? $name : $name . 'Controller';
        $resource = strtolower((string) preg_replace('/Controller$/', '', $class));
        $prefix = $input->getOption('prefix');
        $prefix = is_string($prefix) && $prefix !== '' ? '/' . trim($prefix, '/') : '/' . $resource;
        [$namespace] = $this->rootNamespace();

        $source = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace}\\Controller;

            use Keel\\Routing\\Attribute\\Get;
            use Keel\\Routing\\Attribute\\Prefix;

            #[Prefix('{$prefix}')]
            final class {$class}
            {
                /** @return array<string, mixed> */
                #[Get('', name: '{$resource}.index')]
                public function index(): array
                {
                    return ['{$resource}' => []];
                }

                /** @return array<string, mixed> */
                #[Get('/{id:\\d+}', name: '{$resource}.show')]
                public function show(int \$id): array
                {
                    return ['id' => \$id];
                }
            }

            PHP;

        return $this->write($input, $output, 'Controller', $class, $source);
    }
}
