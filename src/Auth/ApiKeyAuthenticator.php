<?php

declare(strict_types=1);

namespace Keel\Auth;

use Keel\Http\Exception\UnauthorizedException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A static key in a header (X-Api-Key by default) resolved through a PrincipalResolver.
 */
final class ApiKeyAuthenticator implements Authenticator
{
    public function __construct(
        private readonly PrincipalResolver $keys,
        private readonly string $header = 'X-Api-Key',
    ) {}

    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        // PSR-7 implementations trim header values; an empty header means no credentials.
        $key = $request->getHeaderLine($this->header);
        if ($key === '') {
            return null;
        }

        return $this->keys->resolve($key) ?? throw new UnauthorizedException('Invalid API key', ['WWW-Authenticate' => $this->challenge()]);
    }

    public function challenge(): string
    {
        return sprintf('ApiKey header="%s"', $this->header);
    }

    public function securityScheme(): SecurityScheme
    {
        return SecurityScheme::apiKey('apiKey', $this->header);
    }
}
