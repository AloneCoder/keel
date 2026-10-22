<?php

declare(strict_types=1);

namespace Keel\Tests\Mail;

use Keel\Container\Container;
use Keel\Container\Definitions;
use Keel\Health\HealthCheck;
use Keel\Health\HealthStatus;
use Keel\Kernel\DeferredTasks;
use Keel\Kernel\Kernel;
use Keel\Mail\MailConfig;
use Keel\Mail\Mailer;
use Keel\Mail\MailerCheck;
use Keel\Mail\MailerProvider;
use Keel\Mail\MemoryTransport;
use Keel\Mail\MemoryTransportFactory;
use Keel\Testing\TestClient;
use Keel\Tests\Mail\Fixtures\SignUpController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

#[CoversClass(MailConfig::class)]
#[CoversClass(MailerProvider::class)]
#[CoversClass(Mailer::class)]
#[CoversClass(MemoryTransport::class)]
#[CoversClass(MemoryTransportFactory::class)]
#[CoversClass(MailerCheck::class)]
final class MailTest extends TestCase
{
    public function testProviderWiresTheMemoryTransportAndAppliesTheDefaults(): void
    {
        $container = self::container(new MailConfig('memory://default', from: 'My app <no-reply@example.com>', replyTo: ['support@example.com'], headers: ['X-Mailer' => 'Keel']));

        $transport = $container->get(TransportInterface::class);
        self::assertInstanceOf(MemoryTransport::class, $transport);
        self::assertSame($transport, $container->get(MemoryTransport::class));
        self::assertInstanceOf(SymfonyMailer::class, $container->get(MailerInterface::class));
        self::assertSame('memory://default', (string) $transport);
        self::assertNull($transport->last());

        $container->get(Mailer::class)->send(new Email()->to('ann@example.com')->subject('Hi')->text('Hello'));

        $sent = $transport->sent();
        self::assertCount(1, $sent);
        self::assertSame($sent[0], $transport->last());
        $message = $sent[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('no-reply@example.com', $message->getFrom()[0]->getAddress());
        self::assertSame('My app', $message->getFrom()[0]->getName());
        self::assertSame('support@example.com', $message->getReplyTo()[0]->getAddress());
        self::assertSame('Keel', $message->getHeaders()->get('X-Mailer')?->getBodyAsString());
        self::assertSame('no-reply@example.com', $sent[0]->getEnvelope()->getSender()->getAddress());
        self::assertStringContainsString('Subject: Hi', $sent[0]->toString());

        $transport->clear();
        self::assertSame([], $transport->sent());
    }

    public function testExplicitHeadersWinOverTheDefaults(): void
    {
        $container = self::container(new MailConfig('memory://default', from: 'no-reply@example.com', replyTo: ['support@example.com'], headers: ['X-Mailer' => 'Keel']));
        $email = new Email()->from('ann@example.com')->replyTo('ann@example.com')->to('bob@example.com')->subject('Hi')->text('Hello');
        $email->getHeaders()->addTextHeader('X-Mailer', 'custom');

        $container->get(Mailer::class)->send($email);

        $message = $container->get(MemoryTransport::class)->last()?->getOriginalMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('ann@example.com', $message->getFrom()[0]->getAddress());
        self::assertSame('ann@example.com', $message->getReplyTo()[0]->getAddress());
        self::assertSame('custom', $message->getHeaders()->get('X-Mailer')?->getBodyAsString());
    }

    public function testRawMessagesPassThroughUntouched(): void
    {
        $mailer = new Mailer(new SymfonyMailer(new MemoryTransport()), new MailConfig('memory://default', from: 'no-reply@example.com'));
        $raw = new RawMessage("From: raw@example.com\r\nTo: bob@example.com\r\nSubject: Raw\r\n\r\nBody");

        self::assertSame($raw, $mailer->prepare($raw));

        $this->expectException(\LogicException::class);
        $mailer->send($raw); // no envelope can be derived from a raw string
    }

    public function testDeferSendsAfterTheResponse(): void
    {
        $definitions = new Definitions();
        $definitions->instance(MailConfig::class, new MailConfig('memory://default', from: 'no-reply@example.com'));
        $definitions->load(new MailerProvider());
        $kernel = Kernel::build($definitions, [SignUpController::class]);
        $client = TestClient::forKernel($kernel);

        $client->post('/register')->assertOk()->assertJson(['sent_before_response' => 0]);

        $sent = $kernel->container->get(MemoryTransport::class)->sent();
        self::assertCount(1, $sent);
        self::assertStringContainsString('Subject: Welcome', $sent[0]->toString());
        self::assertSame('no-reply@example.com', $sent[0]->getEnvelope()->getSender()->getAddress());
    }

    public function testDeferPreparesNowAndRunsLater(): void
    {
        $deferred = new DeferredTasks();
        $transport = new MemoryTransport();
        $mailer = new Mailer(new SymfonyMailer($transport), new MailConfig('memory://default', from: 'no-reply@example.com'), $deferred);

        $mailer->defer(new Email()->to('bob@example.com')->subject('Later')->text('Hello'));
        self::assertSame(1, $deferred->pending());
        self::assertSame([], $transport->sent());

        self::assertSame(1, $deferred->run());
        self::assertSame('no-reply@example.com', $transport->last()?->getEnvelope()->getSender()->getAddress());
    }

    public function testDeferWithoutDeferredTasksIsALogicError(): void
    {
        $mailer = new Mailer(new SymfonyMailer(new MemoryTransport()), new MailConfig('memory://default'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('DeferredTasks');
        $mailer->defer(new Email()->from('a@example.com')->to('b@example.com')->text('x'));
    }

    public function testNullTransportAndItsHealthCheck(): void
    {
        $container = self::container(new MailConfig('null://null'));

        self::assertInstanceOf(NullTransport::class, $container->get(TransportInterface::class));
        $checks = $container->tagged(HealthCheck::TAG);
        self::assertCount(1, $checks);
        self::assertInstanceOf(MailerCheck::class, $checks[0]);
        self::assertSame('mailer', $checks[0]->name());

        $result = $checks[0]->check();
        self::assertSame(HealthStatus::Up, $result->status);
        self::assertSame(['transport' => 'null://', 'probed' => false], $result->data);
    }

    public function testSmtpHealthCheckReportsDownWhenNothingListens(): void
    {
        $port = self::closedPort();
        $check = new MailerCheck(new EsmtpTransport('127.0.0.1', $port, false), 'smtp');

        $result = $check->check();

        self::assertSame('smtp', $check->name());
        self::assertSame(HealthStatus::Down, $result->status);
        self::assertInstanceOf(TransportExceptionInterface::class, $result->exception);
        self::assertSame('smtp://127.0.0.1:' . $port, $result->data['transport']);
    }

    public function testSmtpHealthCheckReportsUpAgainstAFakeServer(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('ext-pcntl is needed to run a fake SMTP server');
        }
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($server, $error ?? 'stream_socket_server failed');
        $address = stream_socket_get_name($server, false);
        self::assertIsString($address);
        $port = (int) substr($address, (int) strrpos($address, ':') + 1);

        $pid = pcntl_fork();
        if ($pid === 0) {
            $conn = stream_socket_accept($server, 5);
            if ($conn !== false) {
                fwrite($conn, "220 fake ESMTP\r\n");
                fgets($conn); // EHLO
                fwrite($conn, "250-fake\r\n250 OK\r\n");
                fgets($conn); // QUIT
                fwrite($conn, "221 Bye\r\n");
                fclose($conn);
            }
            exit(0);
        }
        fclose($server);

        $result = new MailerCheck(new EsmtpTransport('127.0.0.1', $port, false))->check();
        pcntl_waitpid($pid, $status);

        self::assertSame(HealthStatus::Up, $result->status, $result->detail ?? '');
        self::assertSame('smtp://127.0.0.1:' . $port, $result->data['transport']);
        self::assertIsFloat($result->data['ms']);
    }

    public function testMemoryTransportFactorySupportsOnlyItsScheme(): void
    {
        $transport = new MemoryTransport();
        $factory = new MemoryTransportFactory($transport);

        self::assertTrue($factory->supports(new Dsn('memory', 'default')));
        self::assertFalse($factory->supports(new Dsn('smtp', 'localhost')));
        self::assertSame($transport, $factory->create(new Dsn('memory', 'default')));
    }

    public function testConfigValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DSN');
        new MailConfig(' ');
    }

    public function testConfigRejectsAnInvalidFromAddress(): void
    {
        $this->expectException(\Symfony\Component\Mime\Exception\RfcComplianceException::class);
        new MailConfig('null://null', from: 'not an address');
    }

    public function testConfigRejectsHeaderInjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('line breaks');
        new MailConfig('null://null', headers: ['X-Mailer' => "Keel\r\nBcc: evil@example.com"]);
    }

    public function testConfigRejectsAnInvalidHeaderName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('header name');
        new MailConfig('null://null', headers: ['X Mailer' => 'Keel']);
    }

    public function testUnknownSchemeIsReportedByTheProvider(): void
    {
        $container = self::container(new MailConfig('carrier-pigeon://default'));

        $this->expectException(\Symfony\Component\Mailer\Exception\UnsupportedSchemeException::class);
        $container->get(TransportInterface::class);
    }

    private static function container(MailConfig $config): Container
    {
        $definitions = new Definitions();
        $definitions->instance($config::class, $config);
        $definitions->load(new MailerProvider());

        return new Container($definitions);
    }

    private static function closedPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server);
        $address = stream_socket_get_name($server, false);
        self::assertIsString($address);
        fclose($server);

        return (int) substr($address, (int) strrpos($address, ':') + 1);
    }
}
