<?php

declare(strict_types=1);

namespace Keel\Routing\Attribute;

use Keel\Routing\Exception\RoutingException;

/**
 * A route that only redirects, declared on a controller class (repeatable) so that it needs no
 * method: #[Redirect('/old', '/new', 308)]. The class prefix applies to $from; $to is a path,
 * an absolute URL, and may reuse the placeholders of $from ("/old/{id}" to "/new/{id}").
 * The route is served by Keel\Routing\Redirector.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Redirect
{
    public const string KEY = 'redirect';

    public const array STATUSES = [301, 302, 303, 307, 308];

    /** @var list<string> */
    public array $methods;

    /**
     * @param list<string> $methods
     */
    public function __construct(
        public string $from,
        public string $to,
        public int $status = 302,
        array $methods = ['GET'],
        public ?string $name = null,
    ) {
        if (!in_array($status, self::STATUSES, true)) {
            throw new RoutingException(sprintf('Redirect from "%s" needs a 3xx redirect status (%s), got %d', $from, implode(', ', self::STATUSES), $status));
        }
        if ($to === '' || ($to[0] !== '/' && preg_match('~^https?://~i', $to) !== 1)) {
            throw new RoutingException(sprintf('Redirect from "%s" needs a target path or absolute http(s) URL, got "%s"', $from, $to));
        }
        $this->methods = array_values(array_unique(array_map(strtoupper(...), $methods)));
    }

    /**
     * @return array{to: string, status: int}
     */
    public function value(): array
    {
        return ['to' => $this->to, 'status' => $this->status];
    }
}
