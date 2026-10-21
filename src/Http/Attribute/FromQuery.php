<?php

declare(strict_types=1);

namespace Keel\Http\Attribute;

/**
 * Fills the parameter from the query string: a DTO is hydrated from all query params,
 * a scalar is read from the query param with the parameter's name.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class FromQuery {}
