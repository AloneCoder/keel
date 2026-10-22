<?php

declare(strict_types=1);

namespace Keel\Storage;

use League\Flysystem\FilesystemOperator;
use Psr\Container\ContainerInterface;

/**
 * The disks of StorageConfig by name: $disks->disk('public')->url($path). Disks are built on
 * first use and kept for the worker's life.
 */
final class Disks
{
    /** @var array<string, Storage> */
    private array $disks = [];

    public function __construct(private readonly StorageConfig $config, private readonly ContainerInterface $container) {}

    public function disk(string $name): Storage
    {
        return $this->disks[$name] ??= $this->build($name);
    }

    public function default(): Storage
    {
        return $this->disk($this->config->default);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->config->disks);
    }

    private function build(string $name): Storage
    {
        $disk = $this->config->disks[$name]
            ?? throw new \InvalidArgumentException(sprintf('Unknown disk "%s"; configured: %s', $name, implode(', ', $this->names())));

        if ($disk->driver === 'local') {
            return new LocalStorage((string) $disk->root, $disk->url);
        }

        $filesystem = $this->container->get((string) $disk->service);
        if (!$filesystem instanceof FilesystemOperator) {
            throw new \LogicException(sprintf('Disk "%s": service "%s" is not a League\\Flysystem\\FilesystemOperator', $name, (string) $disk->service));
        }

        return new FlysystemStorage($filesystem, $disk->url);
    }
}
