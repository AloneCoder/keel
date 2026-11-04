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
     * What the keys of an object may cost, in the square of their number. PHP's hash tables are not
     * seeded, so keys chosen to collide ("0", "262144", "524288", ...) make each insert walk all
     * the earlier ones: 2 MB of them decode for over 6 seconds. This budget keeps that to some
     * hundredths of a second whatever the body limit: one object of 10,000 keys, or 100 of 1,000.
     */
    private const int MAX_KEY_COST = 100_000_000;

    /** Keys of an object up to this many cost nothing: colliding or not, the work stays linear in the body. */
    private const int FREE_KEYS = 128;

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
            if (self::keysTooCostly($raw)) {
                throw new ContentTooLargeException('JSON body has objects with too many keys to decode');
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
     * Walks the structure without decoding it and adds up the cost of the keys of each object.
     * Malformed JSON may be misread here, which is harmless: json_decode() refuses it next.
     */
    private static function keysTooCostly(string $raw): bool
    {
        // Colons can only overcount the keys: with few of them no object reaches the budget.
        if (substr_count($raw, ':') ** 2 <= self::MAX_KEY_COST) {
            return false;
        }

        $length = strlen($raw);
        /** @var list<int> $open keys so far of each open object */
        $open = [];
        $cost = 0;
        $at = 0;
        while (true) {
            $at += strcspn($raw, '"{}', $at);
            if ($at >= $length) {
                return false;
            }
            $char = $raw[$at++];
            if ($char === '{') {
                $open[] = 0;
                continue;
            }
            if ($char === '}') {
                array_pop($open);
                continue;
            }

            // A string: on to its closing quote, past escapes.
            while (true) {
                if ($at >= $length) {
                    return false;
                }
                $at += strcspn($raw, '"\\', $at);
                if ($at >= $length) {
                    return false;
                }
                if ($raw[$at] === '"') {
                    break;
                }
                $at += 2;
            }
            $at++;
            $at += strspn($raw, " \t\n\r", $at);
            if ($at < $length && $raw[$at] === ':' && $open !== []) {
                $keys = ++$open[array_key_last($open)];
                if ($keys > self::FREE_KEYS) {
                    $cost += 2 * $keys - 1;
                    if ($cost > self::MAX_KEY_COST) {
                        return true;
                    }
                }
            }
        }
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
