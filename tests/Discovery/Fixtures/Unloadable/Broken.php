<?php

declare(strict_types=1);

namespace Keel\Tests\Discovery\Fixtures\Unloadable;

use Keel\Routing\Attribute\Route;

/**
 * Its parent cannot be loaded (think: optional package not installed, see MissingParent.php).
 * Autoloading this class throws; discovery must skip it instead of aborting the boot.
 */
final class Broken extends MissingParent
{
    #[Route('/broken')]
    public function index(): void {}
}
