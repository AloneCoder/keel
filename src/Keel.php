<?php

declare(strict_types=1);

namespace Keel;

/**
 * Framework identity.
 */
final class Keel
{
    public const string VERSION = '1.5.0';

    /** @deprecated unused since 1.2.1; composer.json enforces the PHP requirement. Kept for BC. */
    public const int MIN_PHP_VERSION_ID = 80400;

    private function __construct() {}
}
