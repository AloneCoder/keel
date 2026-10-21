<?php

declare(strict_types=1);

namespace Keel\View;

/**
 * Register one in config/views.php and load LatteProvider (composer require latte/latte).
 */
final readonly class LatteConfig
{
    /**
     * @param string      $directory     template directory; template names are relative to it
     * @param string|null $tempDirectory compiled template directory; null keeps them in memory per process
     * @param bool        $autoRefresh   recompile when a template changes (development)
     */
    public function __construct(
        public string $directory,
        public ?string $tempDirectory = null,
        public bool $autoRefresh = false,
    ) {}
}
