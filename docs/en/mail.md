# Mail

Namespace `Keel\Mail`. Symfony Mailer wired as a provider: a transport built from a DSN,
Symfony's `MailerInterface`, and Keel's `Mailer` on top, which fills in the configured sender,
`Reply-To` and default headers and can deliver after the response. An optional dependency.

```bash
composer require symfony/mailer      # or: php bin/console keel:install mailer
```

```php
// config/mail.php
return static fn(Env $env): MailConfig => new MailConfig(
    dsn: $env->get('MAILER_DSN') ?? 'null://null',
    from: 'My app <no-reply@example.com>',      // set on messages without a From header
    replyTo: ['support@example.com'],          // set on messages without a Reply-To header
    headers: ['X-Mailer' => 'My app'],         // added to messages that lack the header
);

// config/services.php
return new ServicesConfig([MailerProvider::class]);
```

```php
use Symfony\Component\Mime\Email;

final class RegistrationController
{
    public function __construct(private readonly Users $users, private readonly Mailer $mailer, private readonly ViewRenderer $views) {}

    #[Post('/register')]
    public function register(#[FromBody] SignUp $form): array
    {
        $user = $this->users->create($form);
        $this->mailer->defer(
            new Email()
                ->to(new Address($user->email, $user->name))
                ->subject('Welcome')
                ->html($this->views->render('mail/welcome.twig', ['user' => $user]))
                ->text('Welcome, ' . $user->name),
        );

        return ['id' => $user->id];
    }
}
```

`Mailer::send()` delivers now and lets the transport exception through (`Symfony\Component\
Mailer\Exception\TransportExceptionInterface`). `Mailer::defer()` hands the prepared message to
[`DeferredTasks`](runtime.md#work-after-the-response): it is sent by `Kernel::terminate()` once the client has
the response, so the request does not wait for the SMTP round trip, and a failure is logged
rather than surfaced. Deferred mail is not durable: a crash between the response and the send
loses it; a queue is out of Keel's scope. Both methods accept an optional `Envelope`.

The message is any Symfony Mime `Email` or `Message`: attachments, embedded images, priority,
`Bcc` and the rest are Symfony's. A `RawMessage` (a pre-rendered MIME string) is passed through
without the defaults.

## Transports

The DSN is Symfony Mailer's:

| DSN | Transport |
|---|---|
| `smtp://user:pass@mail.example.com:587` | SMTP with STARTTLS; `smtps://` for implicit TLS on 465; `?verify_peer=0` and the other options as in Symfony |
| `sendmail://default` | the local `sendmail` binary |
| `native://default` | PHP's `mail()` settings |
| `null://null` | drops every message (development without a mail server) |
| `memory://default` | keeps every message in `MemoryTransport` (tests, see below) |
| `mailgun+api://KEY:DOMAIN@default`, `ses+api://...`, ... | an API bridge; each is its own package (`symfony/mailgun-mailer`, `symfony/amazon-mailer`, ...) and needs `symfony/http-client` |
| `failover(smtp://a smtp://b)`, `roundrobin(...)` | several transports |

The transport is built once per worker. The SMTP transport keeps its connection open between
messages and reconnects by itself after `restart_threshold` messages or `ping_threshold`
seconds idle, so worker mode needs no special care; the DSN options are Symfony's.

## Health

`MailerProvider` registers `MailerCheck` under the `keel.health` tag. For an SMTP transport the
readiness check opens a session (connect, `EHLO`, authentication) and closes it, reporting the
latency in `ms`; every other transport is reported `up` with `"probed": false`, as nothing can be
verified without sending. If an unreachable mail server must not take the application out of
readiness, register your own `Definitions` without the tag in a provider loaded after
`MailerProvider`.

## Testing

Point the test environment at `memory://default`: the container's `MemoryTransport` collects
every `SentMessage`, with the original message (`getOriginalMessage()`), the envelope and the
rendered MIME text (`toString()`).

```php
$app = App::boot($projectDir, new Env(['APP_ENV' => 'test', 'MAILER_DSN' => 'memory://default']));
$client = TestClient::forApp($app);

$client->post('/register', ['email' => 'ann@example.com', 'name' => 'Ann'])->assertStatus(201);

$sent = $app->container->get(MemoryTransport::class)->sent();
self::assertCount(1, $sent);
self::assertSame('ann@example.com', $sent[0]->getEnvelope()->getRecipients()[0]->getAddress());
self::assertStringContainsString('Welcome', $sent[0]->toString());
```

Messages accumulate for the life of the process: `clear()` between tests that share a container.
A unit test builds `new Mailer(new Symfony\Component\Mailer\Mailer(new MemoryTransport()), new MailConfig('memory://default', from: ...))`
directly; `defer()` needs a `DeferredTasks` as the third argument and `run()` on it.

## What is registered

`Symfony\Component\Mailer\Transport\TransportInterface` (from the DSN, with `MemoryTransportFactory`
ahead of Symfony's factories), `Symfony\Component\Mailer\Mailer` as `MailerInterface`,
`Keel\Mail\Mailer`, `MemoryTransport`, `MailerCheck`. Symfony's event dispatcher is not wired:
the `MessageEvent` listeners of the full framework (signing, encryption, global headers) are
replaced by `MailConfig` defaults; a message bus is not used.
