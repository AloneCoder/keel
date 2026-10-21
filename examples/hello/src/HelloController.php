<?php

declare(strict_types=1);

namespace Keel\Examples\Hello;

use Keel\Http\Attribute\FromBody;
use Keel\Routing\Attribute\Get;
use Keel\Routing\Attribute\Post;
use Keel\Routing\Attribute\Status;

final class HelloController
{
    /** @return array<string, string> */
    #[Get('/', name: 'home')]
    public function home(): array
    {
        return ['hello' => 'world'];
    }

    #[Get('/hello/{name}', name: 'hello')]
    public function hello(string $name): string
    {
        return '<h1>Hello, ' . htmlspecialchars($name, \ENT_QUOTES) . '!</h1>';
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    #[Post('/echo')]
    #[Status(201)]
    public function echo(#[FromBody] array $payload): array
    {
        return ['received' => $payload];
    }
}
