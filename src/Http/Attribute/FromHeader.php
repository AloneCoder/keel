<?php

declare(strict_types=1);

namespace Keel\Http\Attribute;

/**
 * Fills a scalar parameter from a request header: #[FromHeader('X-Request-Id')] string $id.
 * The value is cast to the parameter type (400 on mismatch); a missing header takes the
 * default, null for a nullable type, otherwise 400.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class FromHeader
{
    public function __construct(public string $name)
    {
        if ($name === '') {
            throw new \InvalidArgumentException('#[FromHeader] needs a header name');
        }
    }
}
