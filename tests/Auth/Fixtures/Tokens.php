<?php

declare(strict_types=1);

namespace Keel\Tests\Auth\Fixtures;

use Keel\Auth\HmacSecrets;
use Keel\Auth\Identity;
use Keel\Auth\Principal;
use Keel\Auth\PrincipalResolver;

final class Tokens implements PrincipalResolver, HmacSecrets
{
    public function resolve(string $credential): ?Principal
    {
        return match ($credential) {
            'user-token' => new Identity('u1', ['user']),
            'admin-token' => new Identity('u2', ['user', 'admin'], ['tenant' => 'acme']),
            default => null,
        };
    }

    public function secretFor(string $keyId): ?string
    {
        return $keyId === 'svc' ? 'top-secret' : null;
    }
}
