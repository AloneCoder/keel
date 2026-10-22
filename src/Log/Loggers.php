<?php

declare(strict_types=1);

namespace Keel\Log;

use Psr\Log\LoggerInterface;

/**
 * Named log channels: $loggers->channel('audit') is a PSR-3 logger writing where
 * LoggerConfig::$channels['audit'] says. The default channel is the LoggerInterface of the
 * container. Built-in channels are StreamLoggers; MonologProvider swaps in Monolog loggers.
 */
final class Loggers
{
    /** @var array<string, LoggerInterface> */
    private array $channels = [];

    /** @var \Closure(string): LoggerInterface */
    private readonly \Closure $factory;

    /**
     * @param (\Closure(string): LoggerInterface)|null $factory builds the logger of a channel; StreamLogger by default
     */
    public function __construct(
        private readonly LoggerConfig $config = new LoggerConfig(),
        private readonly ?LogContext $context = null,
        ?\Closure $factory = null,
    ) {
        $this->factory = $factory ?? fn(string $channel): LoggerInterface => new StreamLogger($this->config, $this->context, $channel);
    }

    public function channel(string $name): LoggerInterface
    {
        return $this->channels[$name] ??= ($this->factory)($name);
    }

    public function default(): LoggerInterface
    {
        return $this->channel($this->config->channel);
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->config->names();
    }
}
