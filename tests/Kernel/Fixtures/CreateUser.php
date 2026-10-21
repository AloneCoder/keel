<?php

declare(strict_types=1);

namespace Keel\Tests\Kernel\Fixtures;

final readonly class CreateUser
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $name,
        public string $email,
        public int $age = 0,
        public ?Address $address = null,
        public Role $role = Role::User,
        public array $tags = [],
        public int|string $ref = 0,
    ) {}
}
