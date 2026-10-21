<?php

declare(strict_types=1);

namespace Keel\Http\Attribute;

/**
 * Fills the parameter from the request body (JSON or parsed form data). A DTO is hydrated,
 * an array parameter receives the decoded body as is.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class FromBody {}
