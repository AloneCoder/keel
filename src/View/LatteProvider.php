<?php

declare(strict_types=1);

namespace Keel\View;

use Keel\Container\Definitions;
use Keel\Container\ServiceProvider;
use Latte\Engine;
use Latte\Loaders\FileLoader;

/**
 * Registers Latte\Engine built from LatteConfig and LatteRenderer as the ViewRenderer.
 */
final class LatteProvider implements ServiceProvider
{
    public function register(Definitions $definitions): void
    {
        if (!class_exists(Engine::class)) {
            throw new \RuntimeException('LatteProvider needs latte/latte: composer require latte/latte');
        }

        $definitions->set(Engine::class)->factory([self::class, 'engine']);
        $definitions->set(LatteRenderer::class)->alias(ViewRenderer::class);
    }

    public static function engine(LatteConfig $config): Engine
    {
        $engine = new Engine();
        $engine->setLoader(new FileLoader($config->directory));
        $engine->setTempDirectory($config->tempDirectory);
        $engine->setAutoRefresh($config->autoRefresh);

        return $engine;
    }
}
