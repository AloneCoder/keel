<?php

declare(strict_types=1);

namespace Keel\Auth;

use Keel\Http\Exception\TooManyRequestsException;
use Keel\Http\Exception\UnauthorizedException;
use Keel\Http\Middleware\TrustedProxiesMiddleware;
use Keel\RateLimit\RateLimiter;
use Keel\RateLimit\RateLimitResult;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Runs the configured authenticators. Shared by AuthMiddleware, AuthGuard and the OpenAPI
 * generator; authenticators are taken from the container on first use.
 *
 * Refused credentials count per client IP, on every path that authenticates. Past
 * AuthConfig::$refusalLimit the answer is 429 instead of 401, and valid credentials get that 429
 * too, so a right guess looks like a wrong one; the check comes after the authenticators, so that
 * parallel guesses cannot slip past a count read before they ran. A principal that authenticated
 * from that IP before the limit was reached keeps working, so a stranger behind the same NAT cannot
 * lock out the clients already there. Needs a ReadableCounters storage to refuse valid credentials.
 */
final class Authentication
{
    /** How long, in seconds, a principal stays known to an IP after authenticating from it. */
    private const int KNOWN_FOR = 3_600;

    /** @var list<Authenticator>|null */
    private ?array $authenticators = null;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?AuthConfig $config = null,
        private readonly ?RateLimiter $limiter = null,
    ) {}

    /**
     * The principal of the first authenticator recognising the request's credentials, or null
     * for an anonymous request. Invalid credentials raise UnauthorizedException.
     *
     * @throws TooManyRequestsException when this IP used up its refused credentials
     */
    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        try {
            foreach ($this->authenticators() as $authenticator) {
                $principal = $authenticator->authenticate($request);
                if ($principal !== null) {
                    $this->admit($request, $principal);

                    return $principal;
                }
            }
        } catch (UnauthorizedException $refused) {
            $this->countRefusal($request);

            throw $refused;
        }

        return null;
    }

    private function countRefusal(ServerRequestInterface $request): void
    {
        $limit = $this->config?->refusalLimit;
        if ($limit === null || $this->limiter === null) {
            return;
        }
        $result = $this->limiter->hit(self::refusals($request), $limit, $this->config->refusalWindow);
        if (!$result->allowed) {
            throw self::tooMany($result);
        }
    }

    private function admit(ServerRequestInterface $request, Principal $principal): void
    {
        $limit = $this->config?->refusalLimit;
        if ($limit === null || $this->limiter === null) {
            return;
        }
        $known = 'auth-known|' . hash('xxh128', self::client($request) . "\0" . $principal->id());
        $locked = $this->limiter->exhausted(self::refusals($request), $limit, $this->config->refusalWindow);
        if ($locked === null) {
            $this->limiter->remember($known, self::KNOWN_FOR);
        } elseif (!$this->limiter->remembered($known)) {
            throw self::tooMany($locked);
        }
    }

    private static function refusals(ServerRequestInterface $request): string
    {
        return 'auth-refused|' . self::client($request);
    }

    private static function client(ServerRequestInterface $request): string
    {
        return RateLimiter::client(TrustedProxiesMiddleware::clientIp($request));
    }

    private static function tooMany(RateLimitResult $result): TooManyRequestsException
    {
        return new TooManyRequestsException('Rate limit exceeded', [
            'RateLimit-Limit' => (string) $result->limit,
            'RateLimit-Remaining' => (string) $result->remaining,
            'RateLimit-Reset' => (string) $result->resetAfter,
            'Retry-After' => (string) $result->resetAfter,
        ]);
    }

    /**
     * A 401 carrying every scheme's challenge.
     */
    public function unauthorized(string $message = 'Authentication required'): UnauthorizedException
    {
        $challenges = array_map(static fn(Authenticator $a): string => $a->challenge(), $this->authenticators());

        return new UnauthorizedException($message, $challenges === [] ? [] : ['WWW-Authenticate' => implode(', ', $challenges)]);
    }

    /** @return list<Authenticator> */
    public function authenticators(): array
    {
        if ($this->authenticators !== null) {
            return $this->authenticators;
        }

        // Built locally and assigned at the end: a failing lookup must not leave a partial list
        // cached for the rest of a worker's life.
        $authenticators = [];
        foreach ($this->config->authenticators ?? [] as $class) {
            $authenticator = $this->container->get($class);
            if (!$authenticator instanceof Authenticator) {
                throw new \LogicException(sprintf('Service "%s" is not a %s', $class, Authenticator::class));
            }
            $authenticators[] = $authenticator;
        }

        return $this->authenticators = $authenticators;
    }

    public static function attach(ServerRequestInterface $request, Principal $principal): ServerRequestInterface
    {
        return $request->withAttribute(Principal::class, $principal)->withAttribute($principal::class, $principal);
    }

    public static function principal(ServerRequestInterface $request): ?Principal
    {
        $principal = $request->getAttribute(Principal::class);

        return $principal instanceof Principal ? $principal : null;
    }
}
