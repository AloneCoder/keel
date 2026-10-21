<?php

declare(strict_types=1);

namespace Keel\Tests\OpenApi\Fixtures;

use Keel\Auth\Attribute\Auth;
use Keel\Http\Attribute\FromBody;
use Keel\Http\Attribute\FromQuery;
use Keel\OpenApi\Attribute\Hidden;
use Keel\OpenApi\Attribute\Operation;
use Keel\OpenApi\Attribute\Response;
use Keel\RateLimit\Attribute\RateLimit;
use Keel\Routing\Attribute\Delete;
use Keel\Routing\Attribute\Get;
use Keel\Routing\Attribute\Post;
use Keel\Routing\Attribute\Prefix;
use Keel\Routing\Attribute\Status;
use Psr\Http\Message\ResponseInterface;

#[Prefix('/tasks')]
#[Operation(tags: ['Tasks'], description: 'Task management')]
final class TaskController
{
    /** @return list<TaskView> */
    #[Get('', name: 'tasks.list')]
    #[Operation(summary: 'List tasks')]
    #[RateLimit(100)]
    public function list(#[FromQuery] TaskFilter $filter): array
    {
        return [];
    }

    #[Post('')]
    #[Status(201)]
    #[Auth('editor')]
    public function create(#[FromBody] CreateTask $task): TaskView
    {
        return new TaskView(1, $task->title, $task->priority);
    }

    #[Get('/{id:\d+}')]
    #[Response(200, 'The task', TaskView::class)]
    #[Response(404, 'No such task')]
    public function show(int $id): ResponseInterface
    {
        throw new \LogicException('not called');
    }

    #[Delete('/{id}')]
    #[Auth]
    #[Operation(deprecated: true, operationId: 'removeTask')]
    public function delete(int $id, #[FromQuery] bool $force = false): void {}

    #[Get('/export')]
    public function export(): string
    {
        return '<html></html>';
    }

    #[Get('/secret')]
    #[Hidden]
    public function secret(): string
    {
        return '';
    }
}
