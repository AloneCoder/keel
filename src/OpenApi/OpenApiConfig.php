<?php

declare(strict_types=1);

namespace Keel\OpenApi;

/**
 * Register one in config/openapi.php. $specPath serves the JSON document, $docsPath an HTML
 * reference built on it; null disables either.
 */
final readonly class OpenApiConfig
{
    /**
     * @param list<array{url: string, description?: string}> $servers
     * @param bool $problemDetails document 400/401/403/429 Problem Details responses where they can occur
     */
    public function __construct(
        public string $title = 'API',
        public string $version = '1.0.0',
        public ?string $description = null,
        public array $servers = [],
        public ?string $specPath = '/openapi.json',
        public ?string $docsPath = '/docs',
        public bool $problemDetails = true,
    ) {}
}
