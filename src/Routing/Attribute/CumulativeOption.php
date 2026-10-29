<?php

declare(strict_types=1);

namespace Keel\Routing\Attribute;

/**
 * A RouteOption that adds to the value its enclosing declarations (parent classes, the
 * controller class) already set instead of replacing it: a requirement such as #[Auth] or
 * #[Can] that a method cannot drop by declaring its own.
 */
interface CumulativeOption extends RouteOption
{
    /**
     * The value with $outer, what the enclosing declarations set under the same key, still in force.
     * Must be exportable with var_export(), like value().
     */
    public function combine(mixed $outer): mixed;
}
