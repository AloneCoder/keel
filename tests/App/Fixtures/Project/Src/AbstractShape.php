<?php

declare(strict_types=1);

namespace Keel\Tests\App\Fixtures\Project\Src;

use Keel\Container\Attribute\Tag;

/**
 * Carries an attribute but is abstract: discoveries must skip it.
 */
#[Tag('shape')]
abstract class AbstractShape
{
    public function anonymous(): object
    {
        return new class {};
    }

    /** @return class-string */
    public function reference(): string
    {
        return Circle::class;
    }
}
