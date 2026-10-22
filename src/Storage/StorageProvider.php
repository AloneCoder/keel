<?php

declare(strict_types=1);

namespace Keel\Storage;

use Keel\Container\Definitions;
use Keel\Container\ServiceProvider;

/**
 * Registers Disks from StorageConfig and the default disk as Storage.
 */
final class StorageProvider implements ServiceProvider
{
    public function register(Definitions $definitions): void
    {
        $definitions->set(Disks::class);
        $definitions->set(Storage::class)->factory([self::class, 'default']);
    }

    public static function default(Disks $disks): Storage
    {
        return $disks->default();
    }
}
