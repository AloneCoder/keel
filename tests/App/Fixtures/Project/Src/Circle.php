<?php

declare(strict_types=1);

namespace Keel\Tests\App\Fixtures\Project\Src;

use Keel\Container\Attribute\Tag;

#[Tag('shape')]
final class Circle implements Shape {}
