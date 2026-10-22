<?php

declare(strict_types=1);

namespace Keel\Tests\Http\Fixtures;

use Keel\Http\Attribute\BodyLimit;
use Keel\Http\Attribute\FromBody;
use Keel\Routing\Attribute\Post;
use Psr\Http\Message\ServerRequestInterface;

final class LimitedController
{
    /** @return array<string, int> */
    #[Post('/default')]
    public function default(ServerRequestInterface $request): array
    {
        return ['bytes' => strlen((string) $request->getBody())];
    }

    /** @return array<string, int> */
    #[Post('/upload')]
    #[BodyLimit('1K', contentTypes: ['text/plain', 'application/*+json'])]
    public function upload(ServerRequestInterface $request): array
    {
        return ['bytes' => strlen((string) $request->getBody())];
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, int>
     */
    #[Post('/json')]
    #[BodyLimit(contentTypes: ['application/json'])]
    public function json(#[FromBody] array $data): array
    {
        return ['fields' => count($data)];
    }
}
