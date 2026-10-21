<?php

declare(strict_types=1);

namespace Keel\Auth;

use Keel\Http\Exception\UnauthorizedException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * "Authorization: Bearer <token>" resolved through a PrincipalResolver (RFC 6750).
 */
final class BearerTokenAuthenticator implements Authenticator
{
    public function __construct(
        private readonly PrincipalResolver $tokens,
        private readonly string $realm = 'api',
        private readonly ?string $format = null,
    ) {}

    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        $header = $request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(\S+)\s*$/i', $header, $matches) !== 1) {
            return null;
        }

        $principal = $this->tokens->resolve($matches[1]);
        if ($principal === null) {
            throw new UnauthorizedException('Invalid or expired token', ['WWW-Authenticate' => $this->challenge() . ', error="invalid_token"']);
        }

        return $principal;
    }

    public function challenge(): string
    {
        return sprintf('Bearer realm="%s"', $this->realm);
    }

    public function securityScheme(): SecurityScheme
    {
        return SecurityScheme::bearer('bearer', $this->format);
    }
}
