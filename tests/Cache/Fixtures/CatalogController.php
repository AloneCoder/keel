<?php

declare(strict_types=1);

namespace Keel\Tests\Cache\Fixtures;

use Keel\Auth\Attribute\Auth;
use Keel\Auth\Principal;
use Keel\Cache\Attribute\OutputCache;
use Keel\Routing\Attribute\Get;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CatalogController
{
    public static int $runs = 0;

    /** @return array<string, mixed> */
    #[Get('/products/{id}')]
    #[OutputCache(ttl: 300, tags: ['products', 'product:{id}'], varyByQuery: ['lang'], varyByHeaders: ['Accept-Language'])]
    public function show(string $id): array
    {
        return ['id' => $id, 'run' => ++self::$runs];
    }

    /** @return array<string, mixed> */
    #[Get('/me/dashboard')]
    #[Auth]
    #[OutputCache(ttl: 60, varyByPrincipal: true)]
    public function dashboard(Principal $user): array
    {
        return ['user' => $user->id(), 'run' => ++self::$runs];
    }

    /** @return array<string, mixed> */
    #[Get('/me/unguarded')]
    #[OutputCache(ttl: 60, varyByPrincipal: true)]
    public function unguarded(ServerRequestInterface $request): array
    {
        return ['user' => $request->getHeaderLine('Authorization'), 'run' => ++self::$runs];
    }

    #[Get('/cookie')]
    #[OutputCache]
    public function cookie(ResponseFactoryInterface $responses): ResponseInterface
    {
        self::$runs++;

        return $responses->createResponse(200)->withHeader('Set-Cookie', 'a=b');
    }

    #[Get('/private')]
    #[OutputCache]
    public function private(ResponseFactoryInterface $responses): ResponseInterface
    {
        self::$runs++;

        return $responses->createResponse(200)->withHeader('Cache-Control', 'private, max-age=10');
    }

    /** @return array<string, int> */
    #[Get('/plain')]
    public function plain(): array
    {
        return ['run' => ++self::$runs];
    }
}
