<?php

declare(strict_types=1);

namespace Keel\Auth;

/**
 * Plain Principal: an id, roles and free-form attributes (token scopes, tenant, ...).
 */
final readonly class Identity implements Principal
{
    /**
     * @param list<string>         $roles
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private string $id,
        private array $roles = [],
        private array $attributes = [],
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function roles(): array
    {
        return $this->roles;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function get(string $attribute, mixed $default = null): mixed
    {
        return $this->attributes[$attribute] ?? $default;
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return $this->attributes;
    }
}
