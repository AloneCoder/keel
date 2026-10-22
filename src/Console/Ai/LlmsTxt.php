<?php

declare(strict_types=1);

namespace Keel\Console\Ai;

use Keel\OpenApi\OpenApiConfig;

/**
 * Builds an llms.txt (llmstxt.org) describing the HTTP API from its OpenAPI document: the title
 * and description, every operation with its summary, and links to the OpenAPI document and the
 * reference page, so a language model can find its way around the API.
 *
 * @internal used by keel:ai:init
 */
final class LlmsTxt
{
    private const array METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

    /**
     * @param array<string, mixed> $document an OpenAPI document as Keel\OpenApi\Generator returns it
     */
    public static function render(array $document, OpenApiConfig $config): string
    {
        $info = is_array($document['info'] ?? null) ? $document['info'] : [];
        $title = is_string($info['title'] ?? null) ? $info['title'] : $config->title;
        $description = is_string($info['description'] ?? null) ? $info['description'] : sprintf('HTTP API, version %s.', is_string($info['version'] ?? null) ? $info['version'] : $config->version);

        $out = '# ' . $title . "\n\n> " . str_replace("\n", "\n> ", trim($description)) . "\n\n";
        $out .= "Requests and responses are JSON; errors are RFC 9457 Problem Details (application/problem+json).\n\n";

        $operations = [];
        $paths = $document['paths'] ?? [];
        foreach (is_array($paths) || is_object($paths) ? (array) $paths : [] as $path => $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (self::METHODS as $method) {
                $operation = $item[$method] ?? null;
                if (!is_array($operation)) {
                    continue;
                }
                $summary = $operation['summary'] ?? $operation['description'] ?? $operation['operationId'] ?? '';
                $line = sprintf('- `%s %s`', strtoupper($method), $path);
                if (is_string($summary) && $summary !== '') {
                    $line .= ': ' . strtok($summary, "\n");
                }
                if (($operation['deprecated'] ?? false) === true) {
                    $line .= ' (deprecated' . (is_string($operation['x-sunset'] ?? null) ? ', removed after ' . $operation['x-sunset'] : '') . ')';
                }
                if (isset($operation['security'])) {
                    $line .= ' [auth]';
                }
                $operations[] = $line;
            }
        }
        if ($operations !== []) {
            $out .= "Operations:\n\n" . implode("\n", $operations) . "\n\n";
        }

        $links = [];
        if ($config->specPath !== null) {
            $links[] = sprintf('- [OpenAPI document](%s): every operation with parameters, request and response schemas', $config->specPath);
        }
        if ($config->docsPath !== null) {
            $links[] = sprintf('- [API reference](%s): the same, rendered for people', $config->docsPath);
        }
        if ($links !== []) {
            $out .= "## Docs\n\n" . implode("\n", $links) . "\n";
        }

        return $out;
    }
}
