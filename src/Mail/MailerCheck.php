<?php

declare(strict_types=1);

namespace Keel\Mail;

use Keel\Health\HealthCheck;
use Keel\Health\HealthResult;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Readiness: an SMTP transport opens a session (connect, EHLO, authentication) and closes it,
 * reporting the latency in milliseconds; a transport that cannot be probed without sending
 * (sendmail, an API bridge, failover and round-robin groups, null, memory) is reported up with
 * its description. Registered by MailerProvider; drop the keel.health tag in a provider of your
 * own if the mail server must not gate readiness.
 */
final class MailerCheck implements HealthCheck
{
    public function __construct(private readonly TransportInterface $transport, private readonly string $name = 'mailer') {}

    public function name(): string
    {
        return $this->name;
    }

    public function check(): HealthResult
    {
        $description = (string) $this->transport;
        if (!$this->transport instanceof SmtpTransport) {
            return HealthResult::up(data: ['transport' => $description, 'probed' => false]);
        }

        $started = hrtime(true);
        try {
            $this->transport->start();
            $this->transport->stop();
        } catch (\Throwable $e) {
            return HealthResult::down($e->getMessage(), ['transport' => $description], $e);
        }

        return HealthResult::up(data: ['transport' => $description, 'ms' => round((hrtime(true) - $started) / 1e6, 2)]);
    }
}
