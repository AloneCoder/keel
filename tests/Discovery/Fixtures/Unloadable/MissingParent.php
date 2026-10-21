<?php

declare(strict_types=1);

namespace Keel\Tests\Discovery\Fixtures\Unloadable;

// Stands in for a parent class from a package that is not installed: including the file fails,
// so every class extending it cannot be loaded either. Declared conditionally so PHP does not
// bind the class at compile time before the throw runs.
if (defined('KEEL_FIXTURE_PARENT_AVAILABLE')) {
    abstract class MissingParent {}
} else {
    throw new \RuntimeException('MissingParent cannot be loaded (simulated missing package)');
}
