<?php

declare(strict_types=1);

namespace Keel\Config;

final readonly class DiscoveryConfig
{
    /**
     * @param list<string> $paths directories scanned for attributes; never vendor/
     */
    public function __construct(public array $paths) {}

    public static function forProject(string $projectDir): self
    {
        return new self([$projectDir . '/src']);
    }
}
