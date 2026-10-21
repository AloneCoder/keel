<?php

declare(strict_types=1);

namespace Keel\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'keel:make:listener', description: 'Generate an event listener discovered by #[Listener]')]
final class MakeListenerCommand extends MakeCommandBase
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('name', InputArgument::REQUIRED, 'Listener name, e.g. SendWelcomeMail');
        $this->addArgument('event', InputArgument::REQUIRED, 'Event class, e.g. App\\Event\\UserRegistered');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $name = self::studly(is_string($name) ? $name : 'Sample');
        if ($name === '' || !self::isClassName($name)) {
            return self::invalid($output, 'Listener name must be a class name, e.g. SendWelcomeMail');
        }
        $class = str_ends_with($name, 'Listener') ? $name : $name . 'Listener';
        $event = $input->getArgument('event');
        $event = ltrim(is_string($event) ? $event : 'App\\Event\\Something', '\\');
        if (!self::isClassReference($event)) {
            return self::invalid($output, sprintf('"%s" is not a class name; expected e.g. App\\Event\\UserRegistered', $event));
        }
        $short = substr($event, (int) strrpos('\\' . $event, '\\'));
        [$namespace] = $this->rootNamespace();

        $source = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace}\\Listener;

            use Keel\\Event\\Attribute\\Listener;
            use {$event};

            final class {$class}
            {
                #[Listener]
                public function __invoke({$short} \$event): void
                {
                    // handle the event
                }
            }

            PHP;

        return $this->write($input, $output, 'Listener', $class, $source);
    }
}
