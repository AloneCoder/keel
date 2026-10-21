<?php

declare(strict_types=1);

namespace Keel\OpenApi\Attribute;

/**
 * Leaves the route, or every route of the class, out of the OpenAPI document.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Hidden {}
