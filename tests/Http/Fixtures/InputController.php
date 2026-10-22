<?php

declare(strict_types=1);

namespace Keel\Tests\Http\Fixtures;

use Keel\Http\Attribute\FromCookie;
use Keel\Http\Attribute\FromHeader;
use Keel\Http\Input;
use Keel\Routing\Attribute\Get;
use Keel\Routing\Attribute\Post;

final class InputController
{
    /** @return array<string, mixed> */
    #[Post('/items/{id}')]
    public function update(Input $input): array
    {
        return [
            'id' => $input->param('id'),
            'name' => $input->string('name', 'anonymous'),
            'qty' => $input->int('qty', 1),
            'tags' => $input->array('tags', []),
            'city' => $input->get('address.city'),
            'only' => $input->only('name', 'qty'),
            'json' => $input->isJson(),
            'wants' => $input->wantsJson(),
            'ip' => $input->ip(),
        ];
    }

    /** @return array<string, mixed> */
    #[Get('/scalars')]
    public function scalars(#[FromHeader('X-Count')] int $count, #[FromCookie] string $theme = 'light', #[FromHeader('X-Flag')] ?bool $flag = null): array
    {
        return ['count' => $count, 'theme' => $theme, 'flag' => $flag];
    }
}
