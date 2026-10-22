<?php

declare(strict_types=1);

namespace Keel\Storage;

/**
 * One disk of StorageConfig: a local directory, or a Flysystem filesystem registered in the
 * container under $service. $url is the public base URL of the disk's root, when it has one.
 */
final readonly class DiskConfig
{
    private function __construct(
        public string $driver,
        public ?string $root,
        public ?string $service,
        public ?string $url,
    ) {}

    public static function local(string $root, ?string $url = null): self
    {
        if ($root === '') {
            throw new \InvalidArgumentException('A local disk needs a root directory');
        }

        return new self('local', $root, null, $url);
    }

    /**
     * @param string $service container id of a League\Flysystem\FilesystemOperator
     */
    public static function flysystem(string $service, ?string $url = null): self
    {
        if ($service === '') {
            throw new \InvalidArgumentException('A Flysystem disk needs the service id of its FilesystemOperator');
        }

        return new self('flysystem', null, $service, $url);
    }
}
