<?php

declare(strict_types=1);

namespace Keel;

/**
 * Framework identity: version and required runtime.
 */
final class Keel
{
    public const string VERSION = '1.2.1';

    public const int MIN_PHP_VERSION_ID = 80400;

    private function __construct() {}
}
