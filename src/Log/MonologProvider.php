<?php

declare(strict_types=1);

namespace Keel\Log;

use Keel\Container\Definitions;
use Keel\Container\ServiceProvider;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;

/**
 * Drives Monolog from LoggerConfig: one Monolog\Logger per channel with a StreamHandler,
 * RotatingFileHandler (daily) or SyslogHandler, JSON or line formatting, and a processor that
 * adds the LogContext fields to every record. Registers the default channel as LoggerInterface
 * and Loggers for the named ones. composer require monolog/monolog.
 */
final class MonologProvider implements ServiceProvider
{
    public function register(Definitions $definitions): void
    {
        if (!class_exists(Logger::class)) {
            throw new \RuntimeException('MonologProvider needs monolog/monolog: composer require monolog/monolog');
        }

        $definitions->set(Loggers::class)->factory([self::class, 'loggers']);
        $definitions->set(Logger::class)->factory([self::class, 'logger'])->alias(LoggerInterface::class);
    }

    public static function loggers(LoggerConfig $config = new LoggerConfig(), ?LogContext $context = null): Loggers
    {
        return new Loggers($config, $context, static fn(string $channel): LoggerInterface => self::channel($config, $channel, $context));
    }

    public static function logger(Loggers $loggers): LoggerInterface
    {
        return $loggers->default();
    }

    /**
     * A Monolog logger for one channel of the config.
     */
    public static function channel(LoggerConfig $config, string $channel, ?LogContext $context = null): Logger
    {
        $settings = $config->channel($channel);
        $name = strtolower($settings['level']);
        if (!in_array($name, ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown log level "%s"', $settings['level']));
        }
        $level = Level::fromName($name);

        if ($settings['syslog'] !== null) {
            $handler = new SyslogHandler($settings['syslog'], \LOG_USER, $level);
        } elseif ($settings['rotate'] === 'daily' && !str_starts_with($settings['stream'], 'php://')) {
            $handler = new RotatingFileHandler($settings['stream'], 0, $level);
        } else {
            $handler = new StreamHandler($settings['stream'], $level);
        }
        $handler->setFormatter($settings['json'] ? new JsonFormatter() : new LineFormatter(null, null, false, true));

        $logger = new Logger($channel, [$handler]);
        if ($config->context && $context !== null) {
            $logger->pushProcessor(static function (LogRecord $record) use ($context): LogRecord {
                foreach ($context->all() as $key => $value) {
                    if (!array_key_exists($key, $record->context)) {
                        $record->extra[$key] = $value;
                    }
                }

                return $record;
            });
        }

        return $logger;
    }
}
