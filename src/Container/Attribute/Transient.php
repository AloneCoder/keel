<?php

declare(strict_types=1);

namespace Keel\Container\Attribute;

/**
 * A new instance is created on every get(); transient services are never reset.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Transient {}
