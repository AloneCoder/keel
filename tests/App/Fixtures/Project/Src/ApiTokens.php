<?php

declare(strict_types=1);

namespace Keel\Tests\App\Fixtures\Project\Src;

use Keel\Auth\Identity;
use Keel\Auth\Principal;
use Keel\Auth\PrincipalResolver;
use Keel\Container\Attribute\Alias;

#[Alias(PrincipalResolver::class)]
final class ApiTokens implements PrincipalResolver
{
    public function resolve(string $credential): ?Principal
    {
        return $credential === 'secret' ? new Identity('alice', ['admin']) : null;
    }
}
