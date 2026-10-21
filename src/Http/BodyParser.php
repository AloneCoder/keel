<?php

declare(strict_types=1);

namespace Keel\Http;

use Keel\Http\Exception\BadRequestException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Decodes the request body into an array: JSON for application/json and *+json,
 * otherwise the already parsed body (form data) when it is an array.
 *
 * @internal
 */
final class BodyParser
{
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
}
