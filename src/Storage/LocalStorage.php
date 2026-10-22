<?php

declare(strict_types=1);

namespace Keel\Storage;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Files under one directory. Writes are atomic (temporary file, then rename); missing
 * directories are created with $directoryMode. With $baseUrl (the URL the web server serves
 * the root under) url() works.
 */
final class LocalStorage implements Storage
{
    private readonly string $root;

    public function __construct(string $root, private readonly ?string $baseUrl = null, private readonly int $directoryMode = 0o755)
    {
        $this->root = rtrim($root, '/\\');
        if ($this->root === '') {
            throw new StorageException('LocalStorage needs a root directory');
        }
    }

    public function write(string $path, mixed $contents): void
    {
        $file = $this->file($path);
        $this->prepare(dirname($file));
        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $written = is_resource($contents)
            ? (($target = @fopen($temporary, 'wb')) !== false && stream_copy_to_stream($contents, $target) !== false && fclose($target))
            : (is_string($contents) && @file_put_contents($temporary, $contents) !== false);
        if (!$written || !@rename($temporary, $file)) {
            @unlink($temporary);
            throw new StorageException(sprintf('Cannot write "%s"', $path));
        }
    }

    public function upload(UploadedFileInterface $file, string $path): void
    {
        if ($file->getError() !== \UPLOAD_ERR_OK) {
            throw new StorageException(sprintf('Upload failed with error %d', $file->getError()));
        }
        $target = $this->file($path);
        $this->prepare(dirname($target));
        $file->moveTo($target);
    }

    public function read(string $path): string
    {
        $contents = @file_get_contents($this->existing($path));
        if ($contents === false) {
            throw new StorageException(sprintf('Cannot read "%s"', $path));
        }

        return $contents;
    }

    public function readStream(string $path)
    {
        $stream = @fopen($this->existing($path), 'rb');
        if ($stream === false) {
            throw new StorageException(sprintf('Cannot read "%s"', $path));
        }

        return $stream;
    }

    public function exists(string $path): bool
    {
        return is_file($this->file($path));
    }

    public function delete(string $path): void
    {
        $file = $this->file($path);
        if (is_file($file) && !@unlink($file)) {
            throw new StorageException(sprintf('Cannot delete "%s"', $path));
        }
    }

    public function move(string $from, string $to): void
    {
        $target = $this->file($to);
        $this->prepare(dirname($target));
        if (!@rename($this->existing($from), $target)) {
            throw new StorageException(sprintf('Cannot move "%s" to "%s"', $from, $to));
        }
    }

    public function size(string $path): int
    {
        $size = @filesize($this->existing($path));
        if ($size === false) {
            throw new StorageException(sprintf('Cannot stat "%s"', $path));
        }

        return $size;
    }

    public function lastModified(string $path): int
    {
        $time = @filemtime($this->existing($path));
        if ($time === false) {
            throw new StorageException(sprintf('Cannot stat "%s"', $path));
        }

        return $time;
    }

    public function url(string $path): string
    {
        if ($this->baseUrl === null) {
            throw new StorageException('This disk has no base URL; files on it are not public');
        }

        return Path::url($this->baseUrl, Path::normalize($path));
    }

    public function root(): string
    {
        return $this->root;
    }

    private function file(string $path): string
    {
        return $this->root . '/' . Path::normalize($path);
    }

    private function existing(string $path): string
    {
        $file = $this->file($path);
        if (!is_file($file)) {
            throw new StorageException(sprintf('File "%s" does not exist', $path));
        }

        return $file;
    }

    private function prepare(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, $this->directoryMode, true) && !is_dir($directory)) {
            throw new StorageException(sprintf('Cannot create directory "%s"', $directory));
        }
    }
}
