<?php

declare(strict_types=1);

namespace Keel\Mail;

use Keel\Kernel\DeferredTasks;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Sends Symfony Mime messages through the configured transport, with the From, Reply-To and
 * default headers of MailConfig applied to messages that do not set them. send() delivers now;
 * defer() delivers after the response through DeferredTasks, so the client does not wait for
 * the SMTP round trip:
 *
 *   public function register(#[FromBody] SignUp $form, Mailer $mailer): array
 *   {
 *       $user = $this->users->create($form);
 *       $mailer->defer(new Email()->to($user->email)->subject('Welcome')->text('...'));
 *
 *       return ['id' => $user->id];
 *   }
 *
 * Deferred messages are not durable: a crash between the response and the send loses them.
 */
final class Mailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly MailConfig $config,
        private readonly ?DeferredTasks $deferred = null,
    ) {}

    /**
     * Sends now. Transport errors surface as Symfony\Component\Mailer\Exception\TransportExceptionInterface.
     */
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->mailer->send($this->prepare($message), $envelope);
    }

    /**
     * Sends after the response was emitted (Kernel::terminate()). The message is prepared now,
     * so a missing sender is reported to the caller; a failed delivery is logged by DeferredTasks.
     */
    public function defer(RawMessage $message, ?Envelope $envelope = null): void
    {
        if ($this->deferred === null) {
            throw new \LogicException('Mailer::defer() needs DeferredTasks: it is available when the Mailer comes from the container of a running kernel');
        }
        $prepared = $this->prepare($message);
        $this->deferred->defer(fn() => $this->mailer->send($prepared, $envelope));
    }

    /**
     * The message with the configured defaults filled in. RawMessage instances (a pre-rendered
     * MIME string) are passed through untouched.
     */
    public function prepare(RawMessage $message): RawMessage
    {
        if (!$message instanceof Message) {
            return $message;
        }
        $headers = $message->getHeaders();
        if ($this->config->from !== null && !$headers->has('From') && !$headers->has('Sender')) {
            $headers->addMailboxListHeader('From', [Address::create($this->config->from)]);
        }
        if ($this->config->replyTo !== [] && !$headers->has('Reply-To')) {
            $headers->addMailboxListHeader('Reply-To', array_map(Address::create(...), $this->config->replyTo));
        }
        foreach ($this->config->headers as $name => $value) {
            if (!$headers->has($name)) {
                $headers->addTextHeader($name, $value);
            }
        }

        return $message;
    }
}
