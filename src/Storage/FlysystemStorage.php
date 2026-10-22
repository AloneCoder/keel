<?php

declare(strict_types=1);

namespace Keel\Storage;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use Psr\Http\Message\UploadedFileInterface;

/**
 * A Storage over any league/flysystem filesystem (S3, SFTP, Google Cloud, in-memory, ...):
 * build the FilesystemOperator in a provider and point a DiskConfig at its service id.
 */
final class FlysystemStorage implements Storage
{
    public function __construct(private readonly FilesystemOperator $filesystem, private readonly ?string $baseUrl = null)
    {
        if (!interface_exists(FilesystemOperator::class)) {
            throw new \RuntimeException('FlysystemStorage needs league/flysystem: composer require league/flysystem');
        }
    }

    public function write(string $path, mixed $contents): void
    {
        $path = Path::normalize($path);
        try {
            if (is_resource($contents)) {
                $this->filesystem->writeStream($path, $contents);
            } elseif (is_string($contents)) {
                $this->filesystem->write($path, $contents);
            } else {
                throw new StorageException('write() takes a string or a stream');
            }
        } catch (FilesystemException $e) {
            throw new StorageException(sprintf('Cannot write "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    public function upload(UploadedFileInterface $file, string $path): void
    {
        if ($file->getError() !== \UPLOAD_ERR_OK) {
            throw new StorageException(sprintf('Upload failed with error %d', $file->getError()));
        }
        $stream = $file->getStream()->detach();
        if (!is_resource($stream)) {
            throw new StorageException('The uploaded file has no readable stream');
        }
        $this->write($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    public function read(string $path): string
    {
        try {
            return $this->filesystem->read(Path::normalize($path));
        } catch (UnableToReadFile $e) {
            throw new StorageException(sprintf('File "%s" does not exist or cannot be read', $path), 0, $e);
        } catch (FilesystemException $e) {
            throw new StorageException(sprintf('Cannot read "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    public function readStream(string $path)
    {
        try {
            return $this->filesystem->readStream(Path::normalize($path));
        } catch (UnableToReadFile $e) {
            throw new StorageException(sprintf('File "%s" does not exist or cannot be read', $path), 0, $e);
        } catch (FilesystemException $e) {
            throw new StorageException(sprintf('Cannot read "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    public function exists(string $path): bool
    {
        try {
            return $this->filesystem->fileExists(Path::normalize($path));
        } catch (FilesystemException $e) {
            throw new StorageException(sprintf('Cannot stat "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->filesystem->delete(Path::normalize($path));
        } catch (FilesystemException $e) {
            throw new StorageException(sprintf('Cannot delete "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    public function move(string $from, string $to): void
    {
        try {
            $this->filesystem->move(Path::normalize($from), Path::normalize($to));
        } catch (FilesystemException $e) {
            throw new StorageException(sprintf('Cannot move "%s" to "%s": %s', $from, $to, $e->getMessage()), 0, $e);
        }
    }

    public function size(string $path): int
    {
        try {
            return $this->filesystem->fileSize(Path::normalize($path));
        } catch (UnableToRetrieveMetadata|FilesystemException $e) {
            throw new StorageException(sprintf('Cannot stat "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    public function lastModified(string $path): int
    {
        try {
            return $this->filesystem->lastModified(Path::normalize($path));
        } catch (UnableToRetrieveMetadata|FilesystemException $e) {
            throw new StorageException(sprintf('Cannot stat "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    public function url(string $path): string
    {
        if ($this->baseUrl === null) {
            throw new StorageException('This disk has no base URL; files on it are not public');
        }

        return Path::url($this->baseUrl, Path::normalize($path));
    }
}
