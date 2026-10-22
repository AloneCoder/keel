<?php

declare(strict_types=1);

namespace Keel\Mail;

use Keel\Container\Definitions;
use Keel\Container\ServiceProvider;
use Keel\Health\HealthCheck;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Registers Symfony Mailer from MailConfig: the TransportInterface built from the DSN (kept for
 * the life of the worker; SMTP connections are reused and pinged by the transport itself),
 * Symfony's Mailer as MailerInterface, Keel's Mailer on top of it, MemoryTransport for the
 * memory:// DSN, and MailerCheck tagged keel.health. Needs composer require symfony/mailer;
 * API bridges (symfony/mailgun-mailer, symfony/amazon-mailer, ...) are extra packages and need
 * symfony/http-client.
 */
final class MailerProvider implements ServiceProvider
{
    public function register(Definitions $definitions): void
    {
        if (!class_exists(SymfonyMailer::class)) {
            throw new \RuntimeException('MailerProvider needs symfony/mailer: composer require symfony/mailer');
        }

        $definitions->set(MemoryTransport::class);
        $definitions->set(TransportInterface::class)->factory([self::class, 'transport']);
        $definitions->set(SymfonyMailer::class)->factory([self::class, 'symfony'])->alias(MailerInterface::class);
        $definitions->set(Mailer::class);
        $definitions->set(MailerCheck::class)->tag(HealthCheck::TAG);
    }

    public static function transport(MailConfig $config, MemoryTransport $memory, ?LoggerInterface $logger = null): TransportInterface
    {
        $factories = [new MemoryTransportFactory($memory), ...Transport::getDefaultFactories(logger: $logger)];

        return new Transport($factories)->fromString($config->dsn);
    }

    public static function symfony(TransportInterface $transport): SymfonyMailer
    {
        return new SymfonyMailer($transport);
    }
}
