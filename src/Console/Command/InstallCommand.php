<?php

declare(strict_types=1);

namespace Keel\Console\Command;

use Composer\InstalledVersions;
use Keel\App\App;
use Keel\Config\HttpConfig;
use Keel\Config\ServicesConfig;
use Keel\Console\Install\Component;
use Keel\Console\Install\ConfigEditor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'keel:install', description: 'Install a component: composer require, provider and middleware registration, starter config files')]
final class InstallCommand extends Command
{
    /**
     * @param array<string, Component>|null $components what can be installed; default Component::all()
     */
    public function __construct(private readonly App $app, private readonly ?array $components = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('components', InputArgument::IS_ARRAY, 'Components to install; none lists them')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without changing anything')
            ->addOption('no-composer', null, InputOption::VALUE_NONE, 'Do not run composer require')
            ->addOption('composer', null, InputOption::VALUE_REQUIRED, 'Composer executable', 'composer')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite config files that exist');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $available = $this->components ?? Component::all();
        /** @var list<string> $names */
        $names = $input->getArgument('components');
        if ($names === []) {
            $rows = array_map(static fn(Component $c): array => [$c->name, $c->description], array_values($available));
            new Table($output)->setHeaders(['Component', 'What it adds'])->setRows($rows)->render();
            $output->writeln('Install with: keel:install <component>...');

            return Command::SUCCESS;
        }

        $unknown = array_diff($names, array_keys($available));
        if ($unknown !== []) {
            $output->writeln(sprintf('<error>Unknown component(s): %s. Run keel:install without arguments for the list.</error>', implode(', ', $unknown)));

            return Command::FAILURE;
        }

        $components = self::resolve($names, $available);
        $dryRun = $input->getOption('dry-run') === true;
        $prefix = $dryRun ? '<comment>would</comment> ' : '';

        foreach ($components as $component) {
            foreach ($component->extensions as $extension) {
                if (!extension_loaded($extension)) {
                    $output->writeln(sprintf('<comment>%s needs the PHP extension "%s", which is not loaded</comment>', $component->name, $extension));
                }
            }
        }

        $packages = [];
        foreach ($components as $component) {
            foreach ($component->packages as $package) {
                if (!self::installed($package)) {
                    $packages[$package] = true;
                }
            }
        }
        if ($packages !== [] && $input->getOption('no-composer') !== true) {
            $composer = $input->getOption('composer');
            $command = (is_string($composer) ? escapeshellcmd($composer) : 'composer') . ' require ' . implode(' ', array_map(escapeshellarg(...), array_keys($packages)));
            $output->writeln($prefix . 'run ' . $command);
            if (!$dryRun && $this->composer($command) !== 0) {
                $output->writeln('<error>composer require failed; nothing else was changed</error>');

                return Command::FAILURE;
            }
        } elseif ($packages !== []) {
            $output->writeln('Skipped composer require ' . implode(' ', array_keys($packages)));
        }

        $configDir = $this->app->projectDir . '/config';
        $manual = false;
        foreach ($components as $component) {
            foreach ($component->configs as $file => $source) {
                $path = $configDir . '/' . $file;
                if (is_file($path) && $input->getOption('force') !== true) {
                    $output->writeln(sprintf('kept    config/%s (exists)', $file));
                    continue;
                }
                if (!$dryRun) {
                    if (!is_dir($configDir) && !mkdir($configDir, 0o775, true) && !is_dir($configDir)) {
                        throw new \RuntimeException(sprintf('Cannot create directory "%s"', $configDir));
                    }
                    if (file_put_contents($path, $source) === false) {
                        throw new \RuntimeException(sprintf('Cannot write "%s"', $path));
                    }
                }
                $output->writeln(sprintf('%swrote   config/%s', $prefix, $file));
            }
            foreach ($component->providers as $provider) {
                $manual = $this->register($output, $prefix, $configDir . '/services.php', ServicesConfig::class, $provider, 'providers', $dryRun) || $manual;
            }
            foreach ($component->middleware as $middleware) {
                $manual = $this->register($output, $prefix, $configDir . '/http.php', HttpConfig::class, $middleware, 'middleware', $dryRun) || $manual;
            }
            if ($component->notes !== '') {
                $output->writeln(sprintf('<info>%s:</info> %s', $component->name, $component->notes));
            }
        }

        if (!$dryRun && !$this->app->config->debug) {
            $output->writeln('Production caches are stale now: run keel:compile.');
        }

        return $manual ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Components in install order: what one requires comes before it, each once.
     *
     * @param list<string>             $names
     * @param array<string, Component> $available
     *
     * @return list<Component>
     */
    private static function resolve(array $names, array $available): array
    {
        $ordered = [];
        $visit = static function (string $name) use (&$visit, &$ordered, $available): void {
            if (isset($ordered[$name]) || !isset($available[$name])) {
                return;
            }
            foreach ($available[$name]->requires as $required) {
                $visit($required);
            }
            $ordered[$name] = $available[$name];
        };
        foreach ($names as $name) {
            $visit($name);
        }

        return array_values($ordered);
    }

    private static function installed(string $package): bool
    {
        return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package);
    }

    /**
     * @param class-string $configClass
     * @param class-string $entry
     *
     * @return bool true when the entry has to be added by hand
     */
    private function register(OutputInterface $output, string $prefix, string $path, string $configClass, string $entry, string $argument, bool $dryRun): bool
    {
        $relative = 'config/' . basename($path);
        $status = ConfigEditor::addToFile($path, $configClass, $entry, $argument, $dryRun);
        match ($status) {
            ConfigEditor::CREATED => $output->writeln(sprintf('%swrote   %s with %s', $prefix, $relative, $entry)),
            ConfigEditor::ADDED => $output->writeln(sprintf('%sadded   %s to %s', $prefix, $entry, $relative)),
            ConfigEditor::PRESENT => $output->writeln(sprintf('kept    %s (already lists %s)', $relative, $entry)),
            ConfigEditor::MANUAL => $output->writeln(sprintf('<error>Could not find the list in %s: add %s::class to it yourself</error>', $relative, $entry)),
        };

        return $status === ConfigEditor::MANUAL;
    }

    private function composer(string $command): int
    {
        $process = proc_open($command, [\STDIN, \STDOUT, \STDERR], $pipes, $this->app->projectDir);
        if (!is_resource($process)) {
            return 1;
        }

        return proc_close($process);
    }
}
