<?php

declare(strict_types=1);

namespace Keel\Benchmarks;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;

final class SymfonyKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
    }

    public function getCacheDir(): string
    {
        return dirname(__DIR__) . '/var/symfony/cache';
    }

    public function getLogDir(): string
    {
        return dirname(__DIR__) . '/var/symfony/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'bench',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
        ]);
    }

    #[Route('/json/{id<\d+>}', name: 'json')]
    public function json(int $id): JsonResponse
    {
        return new JsonResponse(['id' => $id, 'name' => 'item ' . $id, 'ok' => true]);
    }

    #[Route('/plain')]
    public function plain(): Response
    {
        return new Response('Hello');
    }
}
