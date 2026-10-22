<?php

declare(strict_types=1);

namespace Keel\Tests\Kernel\Fixtures;

use Keel\Http\Exception\NotFoundException;
use Keel\Kernel\DeferredTasks;
use Keel\Routing\Attribute\Get;
use Keel\Routing\Attribute\Post;

final class LifecycleController
{
    /** @var list<string> what deferred tasks did, shared by the test */
    public static array $done = [];

    /** @return array<string, bool> */
    #[Get('/ok')]
    public function ok(): array
    {
        return ['ok' => true];
    }

    #[Get('/missing')]
    public function missing(): never
    {
        throw new NotFoundException('No such thing');
    }

    #[Get('/crash')]
    public function crash(): never
    {
        throw new \RuntimeException('boom');
    }

    /** @return array<string, int> */
    #[Post('/work')]
    public function work(DeferredTasks $deferred): array
    {
        $deferred->defer(static function () use ($deferred): void {
            self::$done[] = 'mail';
            $deferred->defer(static function (): void {
                self::$done[] = 'follow-up';
            });
        });
        $deferred->defer(static fn() => throw new \RuntimeException('webhook down'));
        $deferred->defer(static function (): void {
            self::$done[] = 'cache';
        });

        return ['pending' => $deferred->pending()];
    }
}
