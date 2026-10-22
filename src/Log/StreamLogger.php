<?php

declare(strict_types=1);

namespace Keel\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * Dependency-free PSR-3 logger writing one line per record to a stream, a daily-rotated file
 * or the local syslog, as the channel's settings in LoggerConfig say. The stream is opened on
 * first use and kept open for the life of the worker. Exceptions in the context are rendered
 * as class, message and location; {placeholders} in the message are interpolated; the fields
 * of LogContext are merged into the context of every record.
 */
final class StreamLogger extends AbstractLogger
{
    private const array SYSLOG_PRIORITIES = [
        LogLevel::DEBUG => \LOG_DEBUG,
        LogLevel::INFO => \LOG_INFO,
        LogLevel::NOTICE => \LOG_NOTICE,
        LogLevel::WARNING => \LOG_WARNING,
        LogLevel::ERROR => \LOG_ERR,
        LogLevel::CRITICAL => \LOG_CRIT,
        LogLevel::ALERT => \LOG_ALERT,
        LogLevel::EMERGENCY => \LOG_EMERG,
    ];

    private const array LEVELS = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    private readonly LoggerConfig $config;

    private readonly string $channel;

    /** @var array{stream: string, level: string, json: bool, rotate: string|null, syslog: string|null} */
    private readonly array $settings;

    private readonly int $threshold;

    /** @var resource|null */
    private $stream = null;

    /** The date the open stream was rotated for, with daily rotation. */
    private ?string $day = null;

    private bool $syslogOpen = false;

    /**
     * @param string|null $channel a channel of the config; the default channel when null
     */
    public function __construct(?LoggerConfig $config = null, private readonly ?LogContext $context = null, ?string $channel = null)
    {
        $this->config = $config ?? new LoggerConfig();
        $this->channel = $channel ?? $this->config->channel;
        $this->settings = $this->config->channel($this->channel);
        $this->threshold = self::LEVELS[strtolower($this->settings['level'])]
            ?? throw new InvalidArgumentException(sprintf('Unknown log level "%s"', $this->settings['level']));
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $level = is_string($level) ? strtolower($level) : '';
        $severity = self::LEVELS[$level] ?? throw new InvalidArgumentException(sprintf('Unknown log level "%s"', $level));
        if ($severity < $this->threshold) {
            return;
        }

        if ($this->config->context && $this->context !== null) {
            $context += $this->context->all();
        }

        $this->write($level, $this->format($level, (string) $message, $context));
    }

    /**
     * @param array<mixed> $context
     */
    private function format(string $level, string $message, array $context): string
    {
        $time = date('c');
        $context = self::normalize($context);
        $text = self::interpolate($message, $context);

        if ($this->settings['json']) {
            $record = ['time' => $time, 'level' => $level, 'channel' => $this->channel, 'message' => $text];
            if ($context !== []) {
                $record['context'] = $context;
            }

            return json_encode($record, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
        }

        $suffix = $context === [] ? '' : ' ' . json_encode($context, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        // One record per line: a line break inside the message must not forge another record.
        $text = str_replace(["\r\n", "\r", "\n"], '\n', $text);

        return sprintf("[%s] %s.%s: %s%s\n", $time, $this->channel, strtoupper($level), $text, $suffix);
    }

    /**
     * @param array<mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $replacements['{' . $key . '}'] = $value === null ? 'null' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            }
        }

        return strtr($message, $replacements);
    }

    /**
     * @param array<mixed> $context
     *
     * @return array<mixed>
     */
    private static function normalize(array $context, int $depth = 0): array
    {
        $normalized = [];
        foreach ($context as $key => $value) {
            $normalized[$key] = match (true) {
                $value instanceof \Throwable => [
                    'class' => $value::class,
                    'message' => $value->getMessage(),
                    'at' => $value->getFile() . ':' . $value->getLine(),
                ],
                $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::RFC3339_EXTENDED),
                $value instanceof \UnitEnum => $value instanceof \BackedEnum ? $value->value : $value->name,
                $value instanceof \Stringable => (string) $value,
                is_object($value) => $value instanceof \JsonSerializable ? $value : $value::class,
                is_array($value) => $depth < 5 ? self::normalize($value, $depth + 1) : '[array]',
                is_resource($value) => '[resource]',
                default => $value,
            };
        }

        return $normalized;
    }

    private function write(string $level, string $line): void
    {
        if ($this->settings['syslog'] !== null) {
            if (!$this->syslogOpen) {
                openlog($this->settings['syslog'], \LOG_PID | \LOG_ODELAY, \LOG_USER);
                $this->syslogOpen = true;
            }
            syslog(self::SYSLOG_PRIORITIES[$level] ?? \LOG_INFO, rtrim($line, "\n"));

            return;
        }

        $path = $this->settings['stream'];
        if ($this->settings['rotate'] === 'daily' && !str_starts_with($path, 'php://')) {
            $today = date('Y-m-d');
            if ($this->day !== $today && $this->stream !== null) {
                fclose($this->stream);
                $this->stream = null;
            }
            $this->day = $today;
            $path = self::rotated($path, $today);
        }

        if ($this->stream === null) {
            $stream = @fopen($path, 'ab');
            if ($stream === false) {
                throw new \RuntimeException(sprintf('Cannot open log stream "%s"', $path));
            }
            $this->stream = $stream;
        }

        @fwrite($this->stream, $line);
    }

    /**
     * "var/log/app.log" on 2026-10-22 is "var/log/app-2026-10-22.log".
     */
    public static function rotated(string $path, string $day): string
    {
        $extension = pathinfo($path, \PATHINFO_EXTENSION);
        $base = $extension === '' ? $path : substr($path, 0, -strlen($extension) - 1);

        return $base . '-' . $day . ($extension === '' ? '' : '.' . $extension);
    }
}
