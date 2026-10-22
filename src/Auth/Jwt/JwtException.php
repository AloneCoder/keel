<?php

declare(strict_types=1);

namespace Keel\Auth\Jwt;

/**
 * A token that cannot be trusted: malformed, wrongly signed, expired, for another audience.
 * The message says why, for logs; clients only learn that the token is invalid.
 */
final class JwtException extends \RuntimeException {}
