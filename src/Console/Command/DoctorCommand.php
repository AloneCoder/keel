<?php

declare(strict_types=1);

namespace Keel\Console\Command;

use Keel\App\App;
use Keel\App\Doctor\Doctor;
use Keel\App\Doctor\Finding;
use Keel\App\Doctor\Severity;
use Keel\Keel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'keel:doctor', description: 'Check PHP, extensions, configuration, caches, runtime, packages, the container and health checks of this project')]
final class DoctorCommand extends Command
{
    public function __construct(private readonly App $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('skip-health', null, InputOption::VALUE_NONE, 'Do not run the registered health checks (they may reach databases and services)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf(
            '<options=bold>Keel %s</> doctor for %s (env %s, debug %s)',
            Keel::VERSION,
            $this->app->projectDir,
            $this->app->config->env,
            $this->app->config->debug ? 'on' : 'off',
        ));

        $findings = new Doctor($this->app)->diagnose(!(bool) $input->getOption('skip-health'));
        $counts = [Severity::Ok->value => 0, Severity::Warning->value => 0, Severity::Failure->value => 0];
        $section = null;
        foreach ($findings as $finding) {
            if ($finding->section !== $section) {
                $section = $finding->section;
                $output->writeln('');
                $output->writeln('<comment>' . $section . '</comment>');
            }
            $output->writeln(self::line($finding));
            if ($finding->detail !== null) {
                foreach (explode("\n", $finding->detail) as $detail) {
                    $output->writeln('         ' . $detail);
                }
            }
            $counts[$finding->severity->value]++;
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '%d ok, %d warning(s), %d failure(s)',
            $counts[Severity::Ok->value],
            $counts[Severity::Warning->value],
            $counts[Severity::Failure->value],
        ));

        return Doctor::healthy($findings) ? Command::SUCCESS : Command::FAILURE;
    }

    private static function line(Finding $finding): string
    {
        return match ($finding->severity) {
            Severity::Ok => '  <fg=green>OK  </> ' . $finding->message,
            Severity::Warning => '  <fg=yellow>WARN</> ' . $finding->message,
            Severity::Failure => '  <fg=red>FAIL</> ' . $finding->message,
        };
    }
}
