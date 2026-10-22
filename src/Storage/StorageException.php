<?php

declare(strict_types=1);

namespace Keel\Storage;

/**
 * A file operation failed: missing file, unwritable directory, a path escaping the root.
 */
final class StorageException extends \RuntimeException {}
