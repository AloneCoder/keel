<?php

declare(strict_types=1);

namespace Keel\Mail;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * The memory://default transport: keeps every message instead of sending it, for tests.
 *
 *   $client->post('/register', ['email' => 'ann@example.com'])->assertStatus(201);
 *   $sent = $app->container->get(MemoryTransport::class)->sent();
 *   self::assertStringContainsString('Welcome', $sent[0]->toString());
 *
 * Messages accumulate for the life of the process; call clear() between tests that share a
 * container. Not for production: a worker would keep every mail it ever sent in memory.
 */
final class MemoryTransport extends AbstractTransport
{
    /** @var list<SentMessage> */
    private array $sent = [];

    /**
     * @return list<SentMessage>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public function last(): ?SentMessage
    {
        return $this->sent[array_key_last($this->sent) ?? -1] ?? null;
    }

    public function clear(): void
    {
        $this->sent = [];
    }

    public function __toString(): string
    {
        return 'memory://default';
    }

    protected function doSend(SentMessage $message): void
    {
        $this->sent[] = $message;
    }
}
