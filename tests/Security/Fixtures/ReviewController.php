<?php

declare(strict_types=1);

namespace Keel\Tests\Security\Fixtures;

use Keel\Http\Attribute\FromBody;
use Keel\Idempotency\Attribute\Idempotent;
use Keel\RateLimit\Attribute\RateLimit;
use Keel\Routing\Attribute\Get;
use Keel\Routing\Attribute\Post;
use Keel\Session\Session;

final class ReviewController
{
    public static int $charges = 0;

    /** @var list<string> */
    public static array $sessionIds = [];

    /** @return array<string, mixed> */
    #[Post('/login')]
    public function login(Session $session): array
    {
        $session->set('uid', 1);

        return ['ok' => true];
    }

    /** @return array<string, mixed> */
    #[Post('/pay')]
    #[Idempotent]
    public function pay(): array
    {
        return ['charge' => ++self::$charges];
    }

    /** @return array<string, mixed> */
    #[Post('/rotate')]
    public function rotate(Session $session): array
    {
        $session->regenerate();

        return ['ok' => true];
    }

    /** @return array<string, mixed> */
    #[Get('/whoami')]
    public function whoami(Session $session): array
    {
        self::$sessionIds[] = $session->id();

        return ['ok' => true];
    }

    #[Post('/logout-fails')]
    public function logoutFails(Session $session): never
    {
        $session->destroy();

        throw new \RuntimeException('after the logout');
    }

    #[Post('/write-fails')]
    public function writeFails(Session $session): never
    {
        $session->set('uid', 2);

        throw new \RuntimeException('after the write');
    }

    /**
     * @param array<mixed> $body
     *
     * @return array<string, mixed>
     */
    #[Post('/json')]
    public function json(#[FromBody] array $body): array
    {
        return ['count' => count($body)];
    }

    /** @return array<string, mixed> */
    #[Get('/guarded')]
    #[RateLimit(3, 60, by: 'principal')]
    public function guarded(): array
    {
        return ['ok' => true];
    }
}
