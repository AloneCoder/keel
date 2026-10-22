<?php

declare(strict_types=1);

namespace Keel\Tests\Log;

use Keel\Auth\Authentication;
use Keel\Auth\Identity;
use Keel\Container\Definitions;
use Keel\Http\CallableHandler;
use Keel\Http\Middleware\RequestIdMiddleware;
use Keel\Kernel\Kernel;
use Keel\Log\ChannelConfig;
use Keel\Log\LogContext;
use Keel\Log\LogContextMiddleware;
use Keel\Log\LoggerConfig;
use Keel\Log\Loggers;
use Keel\Log\MonologProvider;
use Keel\Log\StreamLogger;
use Keel\Testing\TestClient;
use Keel\Tests\Kernel\Fixtures\UserController;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

#[CoversClass(ChannelConfig::class)]
#[CoversClass(LoggerConfig::class)]
#[CoversClass(LogContext::class)]
#[CoversClass(LogContextMiddleware::class)]
#[CoversClass(Loggers::class)]
#[CoversClass(StreamLogger::class)]
#[CoversClass(MonologProvider::class)]
final class ChannelsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/channels-' . uniqid();
        mkdir($this->dir, 0o775, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function records(string $file): array
    {
        $records = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($file))) as $line) {
            $record = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($record);
            /** @var array<string, mixed> $record */
            $records[] = $record;
        }

        return $records;
    }

    public function testChannelsOverrideTheDefaults(): void
    {
        $config = new LoggerConfig($this->dir . '/app.log', LogLevel::DEBUG, 'app', rotate: 'daily', channels: [
            'audit' => new ChannelConfig(stream: $this->dir . '/audit.log', level: LogLevel::INFO, json: false),
            'sys' => new ChannelConfig(syslog: 'keel'),
        ]);

        self::assertSame(['app', 'audit', 'sys'], $config->names());
        self::assertSame(['stream' => $this->dir . '/app.log', 'level' => 'debug', 'json' => true, 'rotate' => 'daily', 'syslog' => null], $config->channel('app'));
        self::assertSame(['stream' => $this->dir . '/audit.log', 'level' => 'info', 'json' => false, 'rotate' => null, 'syslog' => null], $config->channel('audit'), 'an own stream is not rotated by the default');
        self::assertSame('keel', $config->channel('sys')['syslog']);
        self::assertSame($config->channel('app'), $config->channel('unknown'));

        $loggers = new Loggers($config);
        $loggers->channel('audit')->debug('hidden');
        $loggers->channel('audit')->info('seen');
        $loggers->default()->info('app line');
        self::assertSame($loggers->channel('audit'), $loggers->channel('audit'));
        self::assertSame(['app', 'audit', 'sys'], $loggers->names());

        self::assertMatchesRegularExpression('/^\[[^\]]+\] audit\.INFO: seen\n$/', (string) file_get_contents($this->dir . '/audit.log'));
        $rotated = StreamLogger::rotated($this->dir . '/app.log', date('Y-m-d'));
        self::assertSame($this->dir . '/app-' . date('Y-m-d') . '.log', $rotated);
        self::assertSame('app', self::records($rotated)[0]['channel']);
        self::assertSame('/var/log/keel-2026-01-01', StreamLogger::rotated('/var/log/keel', '2026-01-01'));

        try {
            new ChannelConfig(rotate: 'weekly');
            self::fail('rotation accepted');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        new LoggerConfig(rotate: 'hourly');
    }

    public function testRequestContextIsMergedIntoEveryRecord(): void
    {
        $context = new LogContext();
        $logger = new StreamLogger(new LoggerConfig($this->dir . '/ctx.log'), $context);
        $middleware = new LogContextMiddleware($context);
        $handler = new CallableHandler(static function (ServerRequestInterface $request) use ($logger): ResponseInterface {
            $logger->info('inside', ['path' => 'mine']);

            return new Psr17Factory()->createResponse();
        });

        $request = new ServerRequest('POST', 'https://app.test/orders', serverParams: ['REMOTE_ADDR' => '203.0.113.7']);
        $request = Authentication::attach($request->withAttribute(RequestIdMiddleware::ATTRIBUTE, 'req-1'), new Identity('u9'));
        $middleware->process($request, $handler);
        self::assertSame(['request_id' => 'req-1', 'method' => 'POST', 'path' => '/orders', 'client_ip' => '203.0.113.7', 'principal' => 'u9'], $context->all());

        $context->reset();
        $logger->info('after reset');
        $records = self::records($this->dir . '/ctx.log');
        self::assertSame(['path' => 'mine', 'request_id' => 'req-1', 'method' => 'POST', 'client_ip' => '203.0.113.7', 'principal' => 'u9'], $records[0]['context'], 'the record context wins over the request context');
        self::assertArrayNotHasKey('context', $records[1]);

        $silent = new StreamLogger(new LoggerConfig($this->dir . '/silent.log', context: false), $context);
        $context->set('request_id', 'x');
        $silent->info('no context');
        self::assertArrayNotHasKey('context', self::records($this->dir . '/silent.log')[0]);
    }

    public function testContextIsResetBetweenRequestsInTheKernel(): void
    {
        $definitions = new Definitions();
        $definitions->set(LogContext::class);
        $kernel = Kernel::build($definitions, [UserController::class], [LogContextMiddleware::class]);
        $context = $kernel->container->get(LogContext::class);

        TestClient::forKernel($kernel)->get('/api/users/html')->assertOk();
        self::assertSame([], $context->all(), 'terminate() reset the context');
    }

    public function testMonologProviderBuildsChannelsFromTheSameConfig(): void
    {
        $config = new LoggerConfig($this->dir . '/mono.log', channels: [
            'audit' => new ChannelConfig(stream: $this->dir . '/mono-audit.log', json: false, rotate: 'daily'),
            'sys' => new ChannelConfig(syslog: 'keel-test', level: LogLevel::ERROR),
        ]);
        $context = new LogContext();
        $context->set('request_id', 'r1');

        $definitions = new Definitions();
        $definitions->instance(LoggerConfig::class, $config);
        $definitions->instance(LogContext::class, $context);
        new MonologProvider()->register($definitions);
        $container = new \Keel\Container\Container($definitions);

        $logger = $container->get(LoggerInterface::class);
        self::assertInstanceOf(Logger::class, $logger);
        $logger->info('hello', ['request_id' => 'own', 'k' => 1]);
        $record = self::records($this->dir . '/mono.log')[0];
        self::assertSame('app', $record['channel']);
        self::assertSame(['request_id' => 'own', 'k' => 1], $record['context']);
        self::assertSame([], $record['extra'], 'a context key is not duplicated into extra');

        $logger->info('again');
        self::assertSame(['request_id' => 'r1'], self::records($this->dir . '/mono.log')[1]['extra']);

        $loggers = $container->get(Loggers::class);
        $loggers->channel('audit')->warning('rotated');
        self::assertFileExists($this->dir . '/mono-audit-' . date('Y-m-d') . '.log');
        self::assertSame($logger, $loggers->default());
        self::assertInstanceOf(Logger::class, $loggers->channel('sys'));
    }
}
