<?php

declare(strict_types=1);

namespace Keel\Storage;

/**
 * The checks for a directory whose content is trusted, such as cached objects or session data:
 * nobody but the user running the application may be able to write to it or replace it.
 */
final class PrivateDirectory
{
    /**
     * Refuses a symlink, a world-writable directory, one owned by another user (root only when not
     * group-writable) and one below a world-writable ancestor without the sticky bit. Ancestors are
     * checked by their canonical path, so an operating system's own symlink (such as /var on macOS)
     * does not weaken or prevent the check. A directory that does not exist yet passes. No result is
     * kept, as it can be swapped while a worker runs.
     *
     * @param string $label what the directory is for, at the start of the message ("Cache directory")
     *
     * @throws \RuntimeException
     */
    public static function assert(string $directory, string $label): void
    {
        if (is_link($directory)) {
            throw new \RuntimeException(sprintf('%s "%s" is a symbolic link; refusing to use it', $label, $directory));
        }
        $directory = realpath($directory) ?: $directory;
        $mode = @fileperms($directory);
        $owner = @fileowner($directory);
        if ($mode === false || $owner === false) {
            return;
        }
        // Its owner can write into it whatever the mode says: another user who created it first
        // (a predictable name in a shared /tmp) would plant files.
        if (($mode & 0o002) !== 0 || ($owner !== self::uid() && ($owner !== 0 || ($mode & 0o020) !== 0))) {
            throw new \RuntimeException(sprintf('%s "%s" is writable by everyone or owned by someone else; use a private directory', $label, $directory));
        }
        if (self::replaceable($directory)) {
            throw new \RuntimeException(sprintf('%s "%s" is below a directory anyone can rename it in; configure a private directory, or set the sticky bit (chmod +t)', $label, $directory));
        }
    }

    /**
     * Whether others can replace this directory through any ancestor, regardless of its own mode:
     * a world-writable ancestor without the sticky bit lets them rename the next path component.
     */
    public static function replaceable(string $directory): bool
    {
        $parent = dirname($directory);
        while (true) {
            $mode = @fileperms($parent);
            if ($mode !== false && ($mode & 0o002) !== 0 && ($mode & 0o1000) === 0) {
                return true;
            }
            $next = dirname($parent);
            if ($next === $parent) {
                return false;
            }
            $parent = $next;
        }
    }

    private static function uid(): int
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();

        return $uid === false ? -1 : $uid;
    }
}
