<?php

declare(strict_types=1);

namespace Keel\View;

/**
 * Register one in config/views.php and load TwigProvider (composer require twig/twig).
 */
final readonly class TwigConfig
{
    /**
     * @param list<string>         $paths   template directories, searched in order
     * @param string|null          $cache   compiled template directory; null disables the cache (development)
     * @param array<string, mixed> $globals variables available in every template
     */
    public function __construct(
        public array $paths,
        public ?string $cache = null,
        public bool $debug = false,
        public bool $strictVariables = true,
        public array $globals = [],
    ) {}
}
