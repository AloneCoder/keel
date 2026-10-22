<?php

declare(strict_types=1);

namespace Keel\Mail;

use Symfony\Component\Mime\Address;

/**
 * Register one in config/mail.php and load MailerProvider (composer require symfony/mailer):
 *
 *   static fn(Env $env): MailConfig => new MailConfig(
 *       dsn: $env->get('MAILER_DSN') ?? 'null://null',
 *       from: 'My app <no-reply@example.com>',
 *   )
 *
 * The DSN is Symfony Mailer's: smtp://user:pass@host:587, sendmail://default, native://default,
 * null://null (drops every message), memory://default (keeps them for tests, see MemoryTransport),
 * or a bridge such as mailgun+api://KEY@default once its package is installed; failover(...) and
 * roundrobin(...) combine several.
 */
final readonly class MailConfig
{
    /**
     * @param string                $dsn     transport DSN
     * @param string|null           $from    "Name <address>" or an address, set on messages without a From header
     * @param list<string>          $replyTo set on messages without a Reply-To header
     * @param array<string, string> $headers text headers added to messages that lack them (X-Mailer, List-Unsubscribe, ...)
     */
    public function __construct(
        public string $dsn,
        public ?string $from = null,
        public array $replyTo = [],
        public array $headers = [],
    ) {
        if (trim($dsn) === '') {
            throw new \InvalidArgumentException('MailConfig needs a transport DSN (smtp://..., sendmail://default, null://null)');
        }
        if (class_exists(Address::class)) {
            foreach ([$from, ...$replyTo] as $address) {
                if ($address !== null) {
                    Address::create($address);
                }
            }
        }
        foreach ($headers as $name => $value) {
            if (!preg_match('/^[!-9;-~]+$/', $name)) {
                throw new \InvalidArgumentException(sprintf('Invalid mail header name "%s"', $name));
            }
            if (preg_match('/[\r\n]/', $value)) {
                throw new \InvalidArgumentException(sprintf('Mail header "%s" must not contain line breaks', $name));
            }
        }
    }
}
