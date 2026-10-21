<?php

declare(strict_types=1);

namespace Keel\Container\Attribute;

/**
 * The service is created as a lazy ghost (or lazy proxy for factories):
 * its constructor runs on first access, not on get().
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Lazy {}
