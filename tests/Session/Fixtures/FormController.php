<?php

declare(strict_types=1);

namespace Keel\Tests\Session\Fixtures;

use Keel\Routing\Attribute\Delete;
use Keel\Routing\Attribute\Get;
use Keel\Routing\Attribute\Post;
use Keel\Session\CsrfToken;

final class FormController
{
    #[Get('/form')]
    public function form(CsrfToken $csrf): string
    {
        return '<form method="post" action="/submit">' . $csrf->field() . '</form>';
    }

    /** @return array<string, mixed> */
    #[Post('/submit')]
    public function submit(CsrfToken $csrf): array
    {
        return ['ok' => true, 'token' => (string) $csrf];
    }

    /** @return array<string, mixed> */
    #[Delete('/api/items/{id}')]
    public function apiDelete(int $id): array
    {
        return ['deleted' => $id];
    }
}
