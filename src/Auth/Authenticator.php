<?php

declare(strict_types=1);

namespace Keel\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * One authentication scheme. Register implementations in AuthConfig::$authenticators; they are
 * tried in order until one returns a Principal.
 */
interface Authenticator
{
    /**
     * Null when the request carries no credentials of this scheme. Credentials that are present
     * but invalid must throw Keel\Http\Exception\UnauthorizedException: a bad token is never
     * silently treated as anonymous.
     */
    public function authenticate(ServerRequestInterface $request): ?Principal;

    /**
     * The WWW-Authenticate challenge sent with 401, e.g. 'Bearer realm="api"'.
     */
    public function challenge(): string;

    public function securityScheme(): SecurityScheme;
}
