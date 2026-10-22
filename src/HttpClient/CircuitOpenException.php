<?php

declare(strict_types=1);

namespace Keel\HttpClient;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * The request was not sent: the circuit of its host is open after repeated failures. A network
 * exception for PSR-18 callers, since like one it means "no response from this host".
 */
final class CircuitOpenException extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(private readonly RequestInterface $request, public readonly string $circuit)
    {
        parent::__construct(sprintf('Circuit for %s is open: the host keeps failing, not calling it for now', $circuit));
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
