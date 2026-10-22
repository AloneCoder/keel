<?php

declare(strict_types=1);

namespace Keel\Auth;

use Keel\Http\Exception\ForbiddenException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route middleware added by #[Signed]: 403 unless the request URL carries a genuine, unexpired
 * signature from UrlSigner.
 */
final class SignedUrlGuard implements MiddlewareInterface
{
    public function __construct(private readonly UrlSigner $signer) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $refusal = match ($this->signer->status($request->getUri())) {
            SignedUrlStatus::Valid => null,
            SignedUrlStatus::Missing => 'This URL must be signed',
            SignedUrlStatus::Invalid => 'Invalid URL signature',
            SignedUrlStatus::Expired => 'This link has expired',
        };
        if ($refusal !== null) {
            throw new ForbiddenException($refusal);
        }

        return $handler->handle($request);
    }
}
