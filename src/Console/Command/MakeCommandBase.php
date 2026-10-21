<?php

declare(strict_types=1);

namespace Keel\Console\Command;

use Keel\App\App;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared machinery of the keel:make:* generators: the project's PSR-4 namespace for src/,
 * class name normalisation and validation, string literals for generated code, and safe file
 * writing (never overwrites without --force).
 *
 * @internal
 */
abstract class MakeCommandBase extends Command
{
    private const string CLASS_NAME = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private const string CLASS_REFERENCE = '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/';

    public function __construct(protected readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite the file if it exists');
    }

    /**
     * @return array{0: string, 1: string} root namespace (no trailing backslash) and its directory
     */
    protected function rootNamespace(): array
    {
        $composer = $this->app->projectDir . '/composer.json';
        if (is_file($composer)) {
            /** @var mixed $data */
            $data = json_decode((string) file_get_contents($composer), true);
            $autoload = is_array($data) ? ($data['autoload'] ?? null) : null;
            $psr4 = is_array($autoload) ? ($autoload['psr-4'] ?? null) : null;
            if (is_array($psr4)) {
                foreach ($psr4 as $namespace => $directory) {
                    if (is_string($namespace) && is_string($directory) && rtrim($directory, '/') === 'src') {
                        return [rtrim($namespace, '\\'), $this->app->projectDir . '/src'];
                    }
                }
                foreach ($psr4 as $namespace => $directory) {
                    if (is_string($namespace) && is_string($directory)) {
                        return [rtrim($namespace, '\\'), $this->app->projectDir . '/' . rtrim($directory, '/')];
                    }
                }
            }
        }

        return ['App', $this->app->projectDir . '/src'];
    }

    protected static function studly(string $name): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $name) ?: [];

        return implode('', array_map(ucfirst(...), array_filter($parts, static fn(string $p): bool => $p !== '')));
    }

    /**
     * A PHP class name without namespace: letters, digits and underscores, not starting with a digit.
     */
    protected static function isClassName(string $name): bool
    {
        return preg_match(self::CLASS_NAME, $name) === 1;
    }

    /**
     * A possibly namespaced class name, as it appears after "use".
     */
    protected static function isClassReference(string $name): bool
    {
        return preg_match(self::CLASS_REFERENCE, $name) === 1;
    }

    /**
     * The value as a single-quoted PHP string literal, so it cannot break out of the generated code.
     */
    protected static function literal(string $value): string
    {
        return var_export($value, true);
    }

    protected static function invalid(OutputInterface $output, string $message): int
    {
        $output->writeln(sprintf('<error>%s</error>', $message));

        return Command::FAILURE;
    }

    protected function write(InputInterface $input, OutputInterface $output, string $subdirectory, string $class, string $source): int
    {
        if (!self::isClassName($class)) {
            return self::invalid($output, sprintf('"%s" is not a valid class name', $class));
        }
        [, $srcDir] = $this->rootNamespace();
        $directory = $subdirectory === '' ? $srcDir : $srcDir . '/' . $subdirectory;
        $path = $directory . '/' . $class . '.php';

        if (is_file($path) && $input->getOption('force') !== true) {
            $output->writeln(sprintf('<error>%s exists; use --force to overwrite</error>', $path));

            return Command::FAILURE;
        }
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Cannot create directory "%s"', $directory));
        }
        if (file_put_contents($path, $source) === false) {
            throw new \RuntimeException(sprintf('Cannot write "%s"', $path));
        }

        $output->writeln('<info>created</info> ' . $path);
        if (!$this->app->config->debug) {
            $output->writeln('Production caches are stale now: run keel:compile.');
        }

        return Command::SUCCESS;
    }
}
