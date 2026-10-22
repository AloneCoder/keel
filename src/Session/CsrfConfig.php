<?php

declare(strict_types=1);

namespace Keel\Session;

/**
 * Settings of CsrfMiddleware. Register one in config/csrf.php to change the field or header
 * name, or to exempt path prefixes (an API under "/api/" that authenticates by token and is
 * not reachable with a browser's ambient cookies).
 */
final readonly class CsrfConfig
{
    /**
     * @param string       $field  name of the form field carrying the token
     * @param string       $header name of the header carrying the token (fetch/XHR requests)
     * @param string       $key    session key the token lives under
     * @param list<string> $except request path prefixes left unchecked
     */
    public function __construct(
        public string $field = '_token',
        public string $header = 'X-CSRF-Token',
        public string $key = '_csrf',
        public array $except = [],
    ) {
        if ($field === '' || $header === '' || $key === '') {
            throw new \InvalidArgumentException('CSRF field, header and session key names must not be empty');
        }
        foreach ($except as $prefix) {
            if ($prefix === '' || $prefix[0] !== '/') {
                throw new \InvalidArgumentException(sprintf('CSRF exempt prefixes must start with "/", got "%s"', $prefix));
            }
        }
    }
}
