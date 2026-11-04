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
    private const string EXECUTABLE = '/\.(?:php\d*|phtml?|pht|phar|phps|shtml?|cgi)(?:\.|$)|^\.(?:htaccess|user\.ini)$/i';

    /**
     * Whether a web server serving this file might run it (".php", also "x.php.png" for Apache's
     * AddHandler) or obey it (.htaccess, .user.ini). Disks with a public URL refuse such names.
     * Not .html or .svg: they run in the visitor's browser, which is for the application to rule
     * out (#[File] with an allowlist, a separate domain for user uploads).
     */
    public static function isExecutable(string $path): bool
    {
        return preg_match(self::EXECUTABLE, basename(str_replace('\\', '/', $path))) === 1;
    }

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
