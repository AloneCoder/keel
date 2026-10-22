<?php

declare(strict_types=1);

namespace Keel\Http\Attribute;

/**
 * Fills a scalar parameter from a cookie: #[FromCookie] string $theme = 'light', or
 * #[FromCookie('session_theme')] to name the cookie. Cast like #[FromHeader]; a missing cookie
 * takes the default, null for a nullable type, otherwise 400.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class FromCookie
{
    /**
     * @param string|null $name cookie name; the parameter's name when null
     */
    public function __construct(public ?string $name = null) {}
}
