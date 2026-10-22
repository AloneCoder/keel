<?php

declare(strict_types=1);

namespace Keel\Session;

/**
 * The CSRF token of the current session, left on the request by CsrfMiddleware and injected
 * into handlers by type: public function form(CsrfToken $csrf). Put field() into HTML forms
 * and send value() in the configured header from fetch/XHR.
 */
final readonly class CsrfToken implements \Stringable
{
    public function __construct(
        public string $value,
        public string $fieldName = '_token',
        public string $headerName = 'X-CSRF-Token',
    ) {}

    /**
     * A hidden input for an HTML form.
     */
    public function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars($this->fieldName, \ENT_QUOTES | \ENT_HTML5),
            htmlspecialchars($this->value, \ENT_QUOTES | \ENT_HTML5),
        );
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
