<?php

declare(strict_types=1);

namespace Keel\Http;

use Keel\Http\Exception\BadRequestException;
use Keel\Http\Exception\ContentTooLargeException;
use Keel\Http\Middleware\BodyLimitConfig;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Decodes the request body into an array: JSON for application/json and *+json,
 * otherwise the already parsed body (form data) when it is an array.
 *
 * @internal
 */
final class BodyParser
{
    /** What one decoded JSON object or array costs at most: a PHP array with its first buckets. */
    private const int CONTAINER_BYTES = 512;

    /**
     * @return array<string, mixed>
     */
    public function parse(ServerRequestInterface $request): array
    {
        $contentType = strtolower($request->getHeaderLine('Content-Type'));
        $mediaType = trim(explode(';', $contentType, 2)[0]);

        if ($mediaType === 'application/json' || str_ends_with($mediaType, '+json')) {
            $raw = (string) $request->getBody();
            if (trim($raw) === '') {
                return [];
            }
            // Every [ or { becomes a PHP array of a few hundred bytes: "[[1],[1],...]" within a 2M
            // body limit decodes to over 100 MB and the worker dies on memory_limit. Count them
            // first (brackets inside strings count too, which errs on the safe side).
            $containers = substr_count($raw, '[') + substr_count($raw, '{');
            if ($containers * self::CONTAINER_BYTES > self::memoryAvailable()) {
                throw new ContentTooLargeException(sprintf('JSON body has too many objects and arrays (%d) to decode', $containers));
            }

            try {
                $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new BadRequestException('Malformed JSON body: ' . $e->getMessage(), previous: $e);
            }

            if (!is_array($decoded)) {
                throw new BadRequestException('JSON body must be an object or an array');
            }

            /** @var array<string, mixed> $decoded */
            return $decoded;
        }

        $parsed = $request->getParsedBody();

        /** @var array<string, mixed> */
        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Half of what memory_limit leaves, so the decoded body and its hydration both fit.
     */
    private static function memoryAvailable(): int
    {
        try {
            $limit = BodyLimitConfig::parseSize((string) ini_get('memory_limit'));
        } catch (\InvalidArgumentException) {
            return \PHP_INT_MAX; // -1: no limit
        }

        return $limit === 0 ? \PHP_INT_MAX : intdiv(max(0, $limit - memory_get_usage()), 2);
    }
}
