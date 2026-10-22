<?php

declare(strict_types=1);

namespace Keel\Tests\Http\Fixtures;

use Keel\Http\Attribute\CacheControl;
use Keel\Routing\Attribute\Get;
use Keel\Routing\Attribute\Post;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

final class CachedController
{
    /** @return array<string, mixed> */
    #[Get('/public')]
    #[CacheControl(maxAge: 3600, public: true)]
    public function public(): array
    {
        return ['v' => 1];
    }

    /** @return array<string, mixed> */
    #[Get('/private')]
    #[CacheControl(maxAge: 60, mustRevalidate: true, etag: false)]
    public function private(): array
    {
        return ['v' => 2];
    }

    /** @return array<string, mixed> */
    #[Get('/secret')]
    #[CacheControl(noStore: true)]
    public function secret(): array
    {
        return ['v' => 3];
    }

    /** @return array<string, mixed> */
    #[Get('/plain')]
    public function plain(): array
    {
        return ['v' => 4];
    }

    #[Get('/own')]
    #[CacheControl(maxAge: 10)]
    public function own(ResponseFactoryInterface $responses): ResponseInterface
    {
        return $responses->createResponse(200)
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('ETag', '"handler"')
            ->withHeader('Last-Modified', 'Wed, 21 Oct 2015 07:28:00 GMT');
    }

    /** @return array<string, mixed> */
    #[Post('/public')]
    #[CacheControl(maxAge: 3600, public: true)]
    public function update(): array
    {
        return ['v' => 1];
    }
}
