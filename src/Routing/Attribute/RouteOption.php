<?php

declare(strict_types=1);

namespace Keel\Routing\Attribute;

/**
 * An attribute that stores a value on the Route it is declared on (Route::$options[key()]).
 * Declared on the class it applies to every route of the controller; a method-level attribute
 * with the same key overrides it. Extensions such as #[Auth] and #[RateLimit] implement it so
 * their settings travel with the route, through the route cache, to the middleware reading them.
 */
interface RouteOption
{
    public function key(): string;

    /**
     * Must be exportable with var_export(): scalars, arrays of scalars, null.
     */
    public function value(): mixed;
}
