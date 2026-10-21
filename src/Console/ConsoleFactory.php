<?php

declare(strict_types=1);

namespace Keel\Console;

use Keel\App\App;
use Keel\Console\Command\CacheClearCommand;
use Keel\Console\Command\CompileCommand;
use Keel\Console\Command\DebugContainerCommand;
use Keel\Console\Command\MakeCommandCommand;
use Keel\Console\Command\MakeControllerCommand;
use Keel\Console\Command\MakeListenerCommand;
use Keel\Console\Command\MakeMiddlewareCommand;
use Keel\Console\Command\PreloadCommand;
use Keel\Console\Command\RoutesCommand;
use Keel\Keel;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;

/**
 * Builds the Symfony Console application: keel:* commands plus every #[AsCommand] class
 * found by discovery, instantiated lazily from the container.
 */
final class ConsoleFactory
{
    public static function create(App $app): Application
    {
        if (!class_exists(Application::class)) {
            throw new \RuntimeException('The console needs symfony/console: composer require symfony/console');
        }

        $console = new Application('keel', Keel::VERSION);
        $console->addCommands([
            new CompileCommand($app),
            new CacheClearCommand($app),
            new RoutesCommand($app->router),
            new PreloadCommand($app),
            new DebugContainerCommand($app),
            new MakeControllerCommand($app),
            new MakeCommandCommand($app),
            new MakeMiddlewareCommand($app),
            new MakeListenerCommand($app),
        ]);
        $console->setCommandLoader(new ContainerCommandLoader($app->container, $app->commands()));

        return $console;
    }
}
