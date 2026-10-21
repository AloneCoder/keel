<?php

declare(strict_types=1);

namespace Keel\Validation;

/**
 * A validation attribute. Hydrator runs every Rule attribute of a constructor parameter on the
 * cast value and collects the messages into the 400 Problem Details "errors" list.
 */
interface Rule
{
    /**
     * @return string|null the error message, or null when the value is valid
     */
    public function check(mixed $value): ?string;
}
