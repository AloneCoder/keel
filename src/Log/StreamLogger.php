<?php

declare(strict_types=1);

namespace Keel\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * Dependency-free PSR-3 logger writing one line per record to a stream. The stream is opened
 * on first use and kept open for the life of the worker. Exceptions in the context are
 * rendered as class, message and location; {placeholders} in the message are interpolated.
 */
final class StreamLogger extends AbstractLogger
{
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

    private readonly int $threshold;

    /** @var resource|null */
    private $stream = null;

    public function __construct(?LoggerConfig $config = null)
    {
        $this->config = $config ?? new LoggerConfig();
        $this->threshold = self::LEVELS[strtolower($this->config->level)]
            ?? throw new InvalidArgumentException(sprintf('Unknown log level "%s"', $this->config->level));
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $level = is_string($level) ? strtolower($level) : '';
        $severity = self::LEVELS[$level] ?? throw new InvalidArgumentException(sprintf('Unknown log level "%s"', $level));
        if ($severity < $this->threshold) {
            return;
        }

        $this->write($this->format($level, (string) $message, $context));
    }

    /**
     * @param array<mixed> $context
     */
    private function format(string $level, string $message, array $context): string
    {
        $time = date('c');
        $context = self::normalize($context);
        $text = self::interpolate($message, $context);

        if ($this->config->json) {
            $record = ['time' => $time, 'level' => $level, 'channel' => $this->config->channel, 'message' => $text];
            if ($context !== []) {
                $record['context'] = $context;
            }

            return json_encode($record, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
        }

        $suffix = $context === [] ? '' : ' ' . json_encode($context, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return sprintf("[%s] %s.%s: %s%s\n", $time, $this->config->channel, strtoupper($level), $text, $suffix);
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

    private function write(string $line): void
    {
        if ($this->stream === null) {
            $stream = @fopen($this->config->stream, 'ab');
            if ($stream === false) {
                throw new \RuntimeException(sprintf('Cannot open log stream "%s"', $this->config->stream));
            }
            $this->stream = $stream;
        }

        @fwrite($this->stream, $line);
    }
}
