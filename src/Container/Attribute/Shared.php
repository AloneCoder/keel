<?php

declare(strict_types=1);

namespace Keel\Container\Attribute;

/**
 * One instance per container. This is the default; the attribute only documents intent.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Shared {}
