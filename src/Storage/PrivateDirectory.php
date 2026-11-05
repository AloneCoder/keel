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
     * group-writable) and one inside a world-writable directory without the sticky bit. A directory
     * that does not exist yet passes. No result is kept, as it can be swapped while a worker runs.
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
            throw new \RuntimeException(sprintf('%s "%s" is inside a directory anyone can rename it in; configure a private directory, or set the sticky bit on the parent (chmod +t)', $label, $directory));
        }
    }

    /**
     * Whether others can rename entries of the parent, and so replace this directory whatever its
     * own mode: the parent is world-writable without the sticky bit.
     */
    public static function replaceable(string $directory): bool
    {
        $parent = @fileperms(dirname($directory));

        return $parent !== false && ($parent & 0o002) !== 0 && ($parent & 0o1000) === 0;
    }

    private static function uid(): int
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();

        return $uid === false ? -1 : $uid;
    }
}
