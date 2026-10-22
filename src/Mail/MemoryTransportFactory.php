<?php

declare(strict_types=1);

namespace Keel\Mail;

use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Resolves the memory:// scheme to the MemoryTransport instance the container holds, so a test
 * reads back what the application sent.
 */
final class MemoryTransportFactory implements TransportFactoryInterface
{
    public function __construct(private readonly MemoryTransport $transport) {}

    public function create(Dsn $dsn): TransportInterface
    {
        return $this->transport;
    }

    public function supports(Dsn $dsn): bool
    {
        return $dsn->getScheme() === 'memory';
    }
}
