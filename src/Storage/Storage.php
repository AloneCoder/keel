<?php

declare(strict_types=1);

namespace Keel\Storage;

use Psr\Http\Message\UploadedFileInterface;

/**
 * A place to keep files: a directory on disk (LocalStorage) or anything Flysystem reaches
 * (FlysystemStorage, S3 and friends). Paths are relative, "/"-separated, never escaping the
 * root. Inject Storage for the default disk, or Disks to pick one by name.
 */
interface Storage
{
    /**
     * @param string|resource $contents a string or a readable stream
     */
    public function write(string $path, mixed $contents): void;

    /**
     * Moves an uploaded file to $path (streams it; the temporary upload is gone afterwards).
     */
    public function upload(UploadedFileInterface $file, string $path): void;

    /**
     * @throws StorageException when the file does not exist
     */
    public function read(string $path): string;

    /**
     * @return resource a readable stream the caller closes
     *
     * @throws StorageException when the file does not exist
     */
    public function readStream(string $path);

    public function exists(string $path): bool;

    /**
     * Deleting a missing file is not an error.
     */
    public function delete(string $path): void;

    public function move(string $from, string $to): void;

    /**
     * @throws StorageException when the file does not exist
     */
    public function size(string $path): int;

    /**
     * @return int unix timestamp
     *
     * @throws StorageException when the file does not exist
     */
    public function lastModified(string $path): int;

    /**
     * The public URL of a file, when the disk has a base URL.
     *
     * @throws StorageException when the disk is not public
     */
    public function url(string $path): string;
}
