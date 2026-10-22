<?php

declare(strict_types=1);

namespace Keel\Storage;

/**
 * Path hygiene shared by the disks: relative, "/"-separated, no empty or dot segments,
 * no NUL bytes, so a path from a request can never leave the root.
 *
 * @internal
 */
final class Path
{
    public static function normalize(string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new StorageException('A storage path cannot contain NUL bytes');
        }
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new StorageException(sprintf('Storage path "%s" leaves the root', $path));
            }
            $segments[] = $segment;
        }
        if ($segments === [] || preg_match('/^[A-Za-z]:/', $segments[0]) === 1) {
            throw new StorageException(sprintf('Storage path "%s" is empty or absolute', $path));
        }

        return implode('/', $segments);
    }

    /**
     * A URL for a normalized path under a base URL: each segment percent-encoded.
     */
    public static function url(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/') . '/' . implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }
}
