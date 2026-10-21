<?php

declare(strict_types=1);

namespace Keel\Console\Command;

use Keel\App\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Development server. By default PHP's built-in server; with --frankenphp the FrankenPHP
 * binary, optionally in worker mode (--worker) restarting workers when files change (--watch).
 */
#[AsCommand(name: 'keel:serve', description: 'Run a development server (php -S or FrankenPHP)')]
final class ServeCommand extends Command
{
    /** @var list<string> */
    public const array WATCH = ['src/**/*.php', 'config/**/*.php', '.env'];

    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Address to listen on', '127.0.0.1')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Port to listen on', '8080')
            ->addOption('docroot', 'd', InputOption::VALUE_REQUIRED, 'Document root, relative to the project', 'public')
            ->addOption('frankenphp', 'f', InputOption::VALUE_NONE, 'Use the frankenphp binary ($FRANKENPHP_BINARY or from PATH)')
            ->addOption('worker', 'w', InputOption::VALUE_NONE, 'FrankenPHP worker mode (implies --frankenphp)')
            ->addOption('watch', null, InputOption::VALUE_NONE, 'Restart workers when src/, config/ or .env change (implies --worker)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $watch = (bool) $input->getOption('watch');
        $worker = $watch || (bool) $input->getOption('worker');
        $frankenphp = $worker || (bool) $input->getOption('frankenphp');
        $docroot = self::stringOption($input, 'docroot');
        $host = self::stringOption($input, 'host');
        $port = self::stringOption($input, 'port');

        $command = self::command($this->app->projectDir, $docroot, $host, $port, $frankenphp, $worker, $watch);
        if ($command === null) {
            $output->writeln('<error>frankenphp binary not found</error>: install it (https://frankenphp.dev) or set FRANKENPHP_BINARY');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Listening on</info> http://%s:%s (%s)', $host, $port, $frankenphp ? ($worker ? 'FrankenPHP worker mode' : 'FrankenPHP') : 'PHP built-in server'));
        $output->writeln('<comment>' . $command . '</comment>', OutputInterface::VERBOSITY_VERBOSE);

        $code = 0;
        passthru($command, $code);

        return $code;
    }

    /**
     * The shell command line, or null when frankenphp was asked for and cannot be found.
     */
    public static function command(string $projectDir, string $docroot, string $host, string $port, bool $frankenphp, bool $worker, bool $watch): ?string
    {
        $root = rtrim($projectDir, '/') . '/' . trim($docroot, '/');

        if (!$frankenphp) {
            return implode(' ', [escapeshellarg(\PHP_BINARY), '-S', escapeshellarg($host . ':' . $port), '-t', escapeshellarg($root), escapeshellarg($root . '/index.php')]);
        }

        $binary = self::frankenphpBinary();
        if ($binary === null) {
            return null;
        }

        // --no-compress: a development server does not need it, and some builds lack the brotli module it would load.
        $parts = [escapeshellarg($binary), 'php-server', '--no-compress', '--root', escapeshellarg($root), '--listen', escapeshellarg($host . ':' . $port)];
        if ($worker) {
            $parts[] = '--worker';
            $parts[] = escapeshellarg($root . '/index.php');
        }
        if ($watch) {
            foreach (self::WATCH as $pattern) {
                $parts[] = '--watch=' . escapeshellarg(rtrim($projectDir, '/') . '/' . $pattern);
            }
        }

        return implode(' ', $parts);
    }

    private static function frankenphpBinary(): ?string
    {
        $configured = getenv('FRANKENPHP_BINARY');
        if (is_string($configured) && $configured !== '' && is_executable($configured)) {
            return $configured;
        }
        foreach (explode(\PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            $candidate = rtrim($directory, '/') . '/frankenphp';
            if ($directory !== '' && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function stringOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : (is_int($value) ? (string) $value : '');
    }
}
