<?php

declare(strict_types=1);

namespace Keel\Tests\Security\Fixtures;

use Keel\Auth\Attribute\Auth;
use Keel\Auth\Principal;
use Keel\Cache\Attribute\OutputCache;
use Keel\Idempotency\Attribute\Idempotent;
use Keel\Routing\Attribute\Get;
use Keel\Routing\Attribute\Post;
use Keel\Session\Session;

/**
 * Responses that belong to one client: what output caching and idempotency keys must not share.
 */
final class PrivateController
{
    public static int $runs = 0;

    /** @return array<string, mixed> */
    #[Get('/account')]
    #[Auth]
    #[OutputCache]
    public function account(Principal $user): array
    {
        return ['account_of' => $user->id(), 'run' => ++self::$runs];
    }

    /** @return array<string, mixed> */
    #[Get('/report')]
    #[OutputCache]
    #[Auth]
    public function report(Principal $user): array
    {
        return ['report_for' => $user->id(), 'run' => ++self::$runs];
    }

    /** @return array<string, mixed> */
    #[Get('/welcome')]
    #[OutputCache]
    public function welcome(Session $session): array
    {
        if (!$session->has('csrf')) {
            $session->set('csrf', bin2hex(random_bytes(8)));
        }

        return ['hello' => $session->get('user', 'guest'), 'csrf' => $session->get('csrf'), 'run' => ++self::$runs];
    }

    /** @return array<string, bool> */
    #[Post('/login')]
    public function login(Session $session): array
    {
        $session->set('user', 'alice');

        return ['ok' => true];
    }

    /** @return array<string, mixed> */
    #[Post('/payouts')]
    #[Idempotent]
    #[Auth]
    public function payout(Principal $user): array
    {
        return ['payout' => ++self::$runs, 'owner' => $user->id()];
    }
}
