<?php

declare(strict_types=1);

namespace Keel\Tests\Log;

use Keel\Log\LoggerConfig;
use Keel\Log\StreamLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

#[CoversClass(StreamLogger::class)]
#[CoversClass(LoggerConfig::class)]
final class StreamLoggerTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/log-' . uniqid() . '.log';
        @mkdir(dirname($this->file), 0o775, true);
    }

    public function testWritesJsonLinesWithInterpolationAndNormalizedContext(): void
    {
        $logger = new StreamLogger(new LoggerConfig($this->file, LogLevel::INFO, 'api'));

        $logger->debug('hidden');
        $logger->info('user {id} logged in', ['id' => 42, 'ok' => true]);
        $logger->error('boom', ['exception' => new \RuntimeException('bad'), 'when' => new \DateTimeImmutable('2026-01-02T03:04:05Z')]);

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->file))));
        self::assertCount(2, $lines, 'debug is below the threshold');

        $info = json_decode($lines[0], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($info);
        self::assertSame('info', $info['level']);
        self::assertSame('api', $info['channel']);
        self::assertSame('user 42 logged in', $info['message']);
        self::assertSame(['id' => 42, 'ok' => true], $info['context']);

        $error = json_decode($lines[1], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($error);
        self::assertIsArray($error['context']);
        self::assertIsArray($error['context']['exception']);
        self::assertSame(\RuntimeException::class, $error['context']['exception']['class']);
        self::assertSame('bad', $error['context']['exception']['message']);
        self::assertSame('2026-01-02T03:04:05.000+00:00', $error['context']['when']);
    }

    public function testPlainTextFormat(): void
    {
        $logger = new StreamLogger(new LoggerConfig($this->file, json: false));

        $logger->warning('careful', ['n' => 1]);

        self::assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2}T[^\]]+\] app\.WARNING: careful \{"n":1\}\n$/', (string) file_get_contents($this->file));
    }

    public function testPlainTextKeepsOneRecordPerLine(): void
    {
        $logger = new StreamLogger(new LoggerConfig($this->file, json: false));

        $logger->info("login failed for {user}", ['user' => "eve\n[2026-01-01T00:00:00+00:00] app.INFO: login ok for admin\r\n"]);

        $lines = explode("\n", rtrim((string) file_get_contents($this->file), "\n"));
        self::assertCount(1, $lines, 'line breaks in the message cannot forge a record');
        self::assertStringContainsString('login failed for eve\n[2026-01-01T00:00:00+00:00] app.INFO: login ok for admin\n', $lines[0]);
    }

    public function testUnknownLevelsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StreamLogger(new LoggerConfig($this->file, 'loud'));
    }
}
