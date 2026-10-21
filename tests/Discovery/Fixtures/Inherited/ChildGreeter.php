<?php

declare(strict_types=1);

namespace Keel\Tests\Discovery\Fixtures\Inherited;

/**
 * No attribute of its own: the listener is inherited from the parent.
 */
final class ChildGreeter extends AbstractGreeter {}
