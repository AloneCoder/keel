<?php

declare(strict_types=1);

namespace Keel\Storage;

/**
 * Register one in config/storage.php and load StorageProvider:
 *
 *   new StorageConfig(
 *       disks: [
 *           'local' => DiskConfig::local($projectDir . '/var/storage'),
 *           'public' => DiskConfig::local($projectDir . '/public/uploads', '/uploads'),
 *           's3' => DiskConfig::flysystem(S3Filesystem::class, 'https://cdn.example.com'),
 *       ],
 *       default: 'local',
 *   )
 */
final readonly class StorageConfig
{
    /**
     * @param array<string, DiskConfig> $disks
     * @param string                    $default the disk injected as Storage
     */
    public function __construct(public array $disks, public string $default = 'local')
    {
        if ($disks === []) {
            throw new \InvalidArgumentException('StorageConfig needs at least one disk');
        }
        if (!isset($disks[$default])) {
            throw new \InvalidArgumentException(sprintf('Default disk "%s" is not among the disks (%s)', $default, implode(', ', array_keys($disks))));
        }
    }
}
