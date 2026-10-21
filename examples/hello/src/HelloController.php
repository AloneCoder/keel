<?php

declare(strict_types=1);

namespace Keel\Examples\Hello;

use Keel\Http\Attribute\FromBody;
use Keel\Routing\Attribute\Get;
use Keel\Routing\Attribute\Post;
use Keel\Routing\Attribute\Status;

final class HelloController
{
    public function __construct(private readonly Stats $stats) {}

    /** @return array<string, int|string> */
    #[Get('/stats', name: 'stats')]
    public function stats(): array
    {
        $this->stats->requestHits++;
        $this->stats->workerHits++;

        return [
            'pid' => getmypid(),
            'request_hits' => $this->stats->requestHits,   // always 1: reset after every request
            'worker_hits' => $this->stats->workerHits,     // grows in worker mode, 1 under php-fpm
            'runtime' => function_exists('frankenphp_handle_request') ? 'frankenphp' : \PHP_SAPI,
        ];
    }

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
