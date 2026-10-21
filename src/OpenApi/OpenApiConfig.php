<?php

declare(strict_types=1);

namespace Keel\OpenApi;

/**
 * Register one in config/openapi.php. $specPath serves the JSON document, $docsPath an HTML
 * reference built on it; null disables either.
 */
final readonly class OpenApiConfig
{
    /** The reference UI the docs page loads, pinned to a version so the page does not change under you. */
    public const string DEFAULT_DOCS_SCRIPT = 'https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.75.1';

    /**
     * @param list<array{url: string, description?: string}> $servers
     * @param bool   $problemDetails document 400/401/403/429 Problem Details responses where they can occur
     * @param string $docsScript     URL of the Scalar API reference script the docs page loads: pin another
     *                               version or point it at a copy you host
     */
    public function __construct(
        public string $title = 'API',
        public string $version = '1.0.0',
        public ?string $description = null,
        public array $servers = [],
        public ?string $specPath = '/openapi.json',
        public ?string $docsPath = '/docs',
        public bool $problemDetails = true,
        public string $docsScript = self::DEFAULT_DOCS_SCRIPT,
    ) {}
}
