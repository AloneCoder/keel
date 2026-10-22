<?php

declare(strict_types=1);

namespace Keel\Http\Attribute;

use Keel\Http\Middleware\DeprecationMiddleware;
use Keel\Routing\Attribute\MiddlewareProvider;
use Keel\Routing\Attribute\RouteOption;

/**
 * Marks a route (or every route of the class) as deprecated: responses carry the Deprecation
 * header (RFC 9745), Sunset (RFC 8594) when a removal date is given and Link relations to the
 * documentation and the replacement, and the OpenAPI operation gets deprecated: true.
 *
 *   #[Deprecated(since: '2026-07-01', sunset: '2027-01-01', link: 'https://docs.example.com/v2-migration')]
 *
 * Not to be confused with PHP's own #[\Deprecated], which makes calling the method emit E_USER_DEPRECATED.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class Deprecated implements RouteOption, MiddlewareProvider
{
    public const string KEY = 'deprecated';

    private ?int $sinceAt;

    private ?int $sunsetAt;

    /**
     * @param string|null $since     when the deprecation took or takes effect (any date DateTimeImmutable parses, UTC);
     *                               without it the route is announced as deprecated already
     * @param string|null $sunset    when the route stops answering
     * @param string|null $link      documentation of the deprecation (Link rel="deprecation")
     * @param string|null $successor URL of the replacement (Link rel="successor-version")
     */
    public function __construct(
        public ?string $since = null,
        public ?string $sunset = null,
        public ?string $link = null,
        public ?string $successor = null,
    ) {
        $this->sinceAt = self::timestamp($since, 'since');
        $this->sunsetAt = self::timestamp($sunset, 'sunset');
        if ($this->sinceAt !== null && $this->sunsetAt !== null && $this->sunsetAt < $this->sinceAt) {
            throw new \InvalidArgumentException('#[Deprecated] sunset must not be earlier than since');
        }
        foreach (['link' => $link, 'successor' => $successor] as $name => $url) {
            if ($url !== null && preg_match('/^[^\s<>"]+$/D', $url) !== 1) {
                throw new \InvalidArgumentException(sprintf('#[Deprecated] %s "%s" is not a URL that fits a Link header', $name, $url));
            }
        }
    }

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @return array{since: int|null, sunset: int|null, link: string|null, successor: string|null}
     */
    public function value(): array
    {
        return ['since' => $this->sinceAt, 'sunset' => $this->sunsetAt, 'link' => $this->link, 'successor' => $this->successor];
    }

    public function middleware(): array
    {
        return [DeprecationMiddleware::class];
    }

    private static function timestamp(?string $date, string $name): ?int
    {
        if ($date === null) {
            return null;
        }
        try {
            return new \DateTimeImmutable($date, new \DateTimeZone('UTC'))->getTimestamp();
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(sprintf('#[Deprecated] %s "%s" is not a date', $name, $date), previous: $e);
        }
    }
}
