<?php

declare(strict_types=1);

namespace Keel\View;

use Keel\Container\Definitions;
use Keel\Container\ServiceProvider;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Registers Twig\Environment built from TwigConfig and TwigRenderer as the ViewRenderer.
 * One environment serves every request of a worker: compiled templates stay in memory.
 */
final class TwigProvider implements ServiceProvider
{
    public function register(Definitions $definitions): void
    {
        if (!class_exists(Environment::class)) {
            throw new \RuntimeException('TwigProvider needs twig/twig: composer require twig/twig');
        }

        $definitions->set(Environment::class)->factory([self::class, 'environment']);
        $definitions->set(TwigRenderer::class)->alias(ViewRenderer::class);
    }

    public static function environment(TwigConfig $config): Environment
    {
        $twig = new Environment(new FilesystemLoader($config->paths), [
            'cache' => $config->cache ?? false,
            'debug' => $config->debug,
            'auto_reload' => $config->debug,
            'strict_variables' => $config->strictVariables,
        ]);
        foreach ($config->globals as $name => $value) {
            $twig->addGlobal($name, $value);
        }

        return $twig;
    }
}
