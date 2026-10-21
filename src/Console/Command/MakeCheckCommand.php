<?php

declare(strict_types=1);

namespace Keel\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'keel:make:check', description: 'Generate a health check for /health')]
final class MakeCheckCommand extends MakeCommandBase
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('name', InputArgument::REQUIRED, 'Check name, e.g. Database or redis');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $name = self::studly(is_string($name) ? $name : 'Sample');
        $class = str_ends_with($name, 'Check') ? $name : $name . 'Check';
        $label = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', substr($class, 0, -5)));
        [$namespace] = $this->rootNamespace();

        $source = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace}\\Health;

            use Keel\\Container\\Attribute\\Tag;
            use Keel\\Health\\HealthCheck;
            use Keel\\Health\\HealthResult;

            /**
             * Readiness check listed under "{$label}" in /health. Discovery registers it through the tag;
             * HealthMiddleware runs it on every probe, so keep it cheap.
             */
            #[Tag(HealthCheck::TAG)]
            final class {$class} implements HealthCheck
            {
                public function name(): string
                {
                    return '{$label}';
                }

                public function check(): HealthResult
                {
                    \$started = hrtime(true);
                    try {
                        // Touch the dependency here; throw or return HealthResult::down() when it is unreachable.
                    } catch (\\Throwable \$e) {
                        return HealthResult::down(\$e->getMessage());
                    }

                    return HealthResult::up(data: ['ms' => round((hrtime(true) - \$started) / 1e6, 2)]);
                }
            }

            PHP;

        return $this->write($input, $output, 'Health', $class, $source);
    }
}
