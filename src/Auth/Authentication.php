<?php

declare(strict_types=1);

namespace Keel\Auth;

use Keel\Http\Exception\UnauthorizedException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Runs the configured authenticators. Shared by AuthMiddleware, AuthGuard and the OpenAPI
 * generator; authenticators are taken from the container on first use.
 */
final class Authentication
{
    /** @var list<Authenticator>|null */
    private ?array $authenticators = null;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?AuthConfig $config = null,
    ) {}

    /**
     * The principal of the first authenticator recognising the request's credentials, or null
     * for an anonymous request. Invalid credentials raise UnauthorizedException.
     */
    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        foreach ($this->authenticators() as $authenticator) {
            $principal = $authenticator->authenticate($request);
            if ($principal !== null) {
                return $principal;
            }
        }

        return null;
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
        if ($this->authenticators === null) {
            $this->authenticators = [];
            foreach ($this->config->authenticators ?? [] as $class) {
                $authenticator = $this->container->get($class);
                if (!$authenticator instanceof Authenticator) {
                    throw new \LogicException(sprintf('Service "%s" is not a %s', $class, Authenticator::class));
                }
                $this->authenticators[] = $authenticator;
            }
        }

        return $this->authenticators;
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
