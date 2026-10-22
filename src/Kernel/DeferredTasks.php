<?php

declare(strict_types=1);

namespace Keel\Kernel;

use Keel\Container\Resettable;
use Psr\Log\LoggerInterface;

/**
 * Work to do after the response was sent: inject it and defer() a closure, Kernel::terminate()
 * runs the closures once the client has the response (php-fpm and FrankenPHP finish the request
 * first, RoadRunner has already received it). Covers sending a mail, a webhook or warming a cache
 * without a queue:
 *
 *   public function register(#[FromBody] SignUp $form, DeferredTasks $deferred): array
 *   {
 *       $user = $this->users->create($form);
 *       $deferred->defer(fn() => $this->mailer->welcome($user));
 *
 *       return ['id' => $user->id];
 *   }
 *
 * Tasks run in order, a failing task is logged and the next one still runs. They are not durable:
 * a crash or a deploy loses them, and in worker mode they hold the worker until they finish.
 * A task deferred while the tasks run is run in the same pass.
 */
final class DeferredTasks implements Resettable
{
    /** @var list<\Closure(): mixed> */
    private array $tasks = [];

    /**
     * @param \Closure(): mixed $task
     */
    public function defer(\Closure $task): void
    {
        $this->tasks[] = $task;
    }

    public function pending(): int
    {
        return count($this->tasks);
    }

    /**
     * Runs and removes every task; returns how many ran. Called by Kernel::terminate().
     */
    public function run(?LoggerInterface $logger = null): int
    {
        $ran = 0;
        while ($this->tasks !== []) {
            $task = array_shift($this->tasks);
            $ran++;
            try {
                $task();
            } catch (\Throwable $exception) {
                $logger?->error('Deferred task failed: ' . $exception->getMessage(), ['exception' => $exception]);
            }
        }

        return $ran;
    }

    /**
     * Drops tasks that were never run, so they cannot leak into the next request.
     */
    public function reset(): void
    {
        $this->tasks = [];
    }
}
