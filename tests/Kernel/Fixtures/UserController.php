<?php

declare(strict_types=1);

namespace Keel\Tests\Kernel\Fixtures;

use Keel\Http\Attribute\FromBody;
use Keel\Http\Attribute\FromQuery;
use Keel\Http\Exception\ForbiddenException;
use Keel\Routing\Attribute\Delete;
use Keel\Routing\Attribute\Get;
use Keel\Routing\Attribute\Middleware;
use Keel\Routing\Attribute\Post;
use Keel\Routing\Attribute\Prefix;
use Keel\Routing\Attribute\Put;
use Keel\Routing\Attribute\Route;
use Keel\Routing\Attribute\Status;
use Keel\Routing\RouteMatch;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Prefix('/api/users')]
#[Middleware(ClassMiddleware::class)]
final class UserController
{
    public function __construct(private readonly RequestCounter $counter) {}

    /** @return array<string, mixed> */
    #[Get('/{id:\d+}', name: 'users.show')]
    public function show(int $id, ServerRequestInterface $request): array
    {
        $this->counter->hits++;

        return ['id' => $id, 'trace' => $request->getAttribute('trace'), 'hits' => $this->counter->hits];
    }

    #[Post('')]
    #[Status(201)]
    public function create(#[FromBody] CreateUser $command): CreateUser
    {
        return $command;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    #[Put('/{id:\d+}')]
    public function replace(int $id, #[FromBody] array $body): array
    {
        return ['id' => $id, 'body' => $body];
    }

    #[Delete('/{id:\d+}', name: 'users.delete')]
    public function delete(int $id): null
    {
        return null;
    }

    #[Get('/search', name: 'users.search')]
    public function search(#[FromQuery] SearchQuery $query): SearchQuery
    {
        return $query;
    }

    /** @return array<string, mixed> */
    #[Get('/page')]
    public function page(#[FromQuery] int $page = 1, #[FromQuery] ?string $sort = null): array
    {
        return ['page' => $page, 'sort' => $sort];
    }

    /** @return array<string, mixed> */
    #[Get('/{id:\d+}/posts/{slug}', name: 'users.post')]
    public function post(int $id, string $slug, RouteMatch $match): array
    {
        return ['id' => $id, 'slug' => $slug, 'route' => $match->route->name];
    }

    /** @return array<string, mixed> */
    #[Get('/format/{format}')]
    public function format(Format $format): array
    {
        return ['format' => $format->name];
    }

    /** @return array<string, mixed> */
    #[Get('/role/{role}')]
    public function role(Role $role): array
    {
        return ['role' => $role->value];
    }

    /** @return array<string, mixed> */
    #[Get('/time')]
    public function time(ClockInterface $clock): array
    {
        return ['now' => $clock->now()];
    }

    #[Get('/html')]
    public function html(): string
    {
        return '<h1>Hi</h1>';
    }

    #[Get('/raw')]
    public function raw(ResponseFactoryInterface $responses): ResponseInterface
    {
        return $responses->createResponse(418)->withHeader('X-Raw', 'yes');
    }

    #[Get('/secret')]
    #[Middleware(AuthMiddleware::class, MethodMiddleware::class)]
    public function secret(): string
    {
        return 'top secret';
    }

    #[Get('/forbidden')]
    public function forbidden(): never
    {
        throw new ForbiddenException('No access to this resource');
    }

    #[Get('/boom')]
    public function boom(): never
    {
        throw new \RuntimeException('Something broke');
    }

    #[Get('/unresolvable')]
    public function unresolvable(string $missing): string
    {
        return $missing;
    }

    /** @return array<string, string> */
    #[Route('/multi', methods: ['GET', 'POST'], name: 'users.multi')]
    #[Get('/multi-alias')]
    public function multi(ServerRequestInterface $request): array
    {
        return ['method' => $request->getMethod()];
    }

    #[Put('/scalar')]
    public function scalar(#[FromBody] string $body): string
    {
        return $body;
    }
}
