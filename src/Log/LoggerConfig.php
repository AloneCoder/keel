<?php

declare(strict_types=1);

namespace Keel\Log;

use Psr\Log\LogLevel;

/**
 * Settings of the built-in StreamLogger: the default channel plus named channels that override
 * it. Register one in config/logger.php; load MonologProvider to drive Monolog from the same
 * config, or register your own LoggerInterface to replace the logger entirely.
 */
final readonly class LoggerConfig
{
    /**
     * @param string                       $stream   php://stderr (default, what FrankenPHP and RoadRunner collect), php://stdout or a file path
     * @param string                       $level    minimum PSR-3 level to write
     * @param string                       $channel  name of the default channel
     * @param bool                         $json     one JSON object per line, otherwise "[time] channel.LEVEL: message {context}"
     * @param string|null                  $rotate   "daily" rotates a file stream by date
     * @param array<string, ChannelConfig> $channels further channels by name, each overriding the defaults it sets
     * @param bool                         $context  merge the request context (request_id, method, path, client_ip, principal)
     *                                               LogContextMiddleware collected into every record
     */
    public function __construct(
        public string $stream = 'php://stderr',
        public string $level = LogLevel::DEBUG,
        public string $channel = 'app',
        public bool $json = true,
        public ?string $rotate = null,
        public array $channels = [],
        public bool $context = true,
    ) {
        if ($rotate !== null && $rotate !== 'daily') {
            throw new \InvalidArgumentException(sprintf('Log rotation must be "daily" or null, got "%s"', $rotate));
        }
        foreach (array_keys($channels) as $name) {
            if ($name === '') {
                throw new \InvalidArgumentException('Log channels need a name');
            }
        }
    }

    /**
     * The effective settings of a channel: its own values over the defaults. The default channel
     * (or an unknown name) gets the defaults.
     *
     * @return array{stream: string, level: string, json: bool, rotate: string|null, syslog: string|null}
     */
    public function channel(string $name): array
    {
        $channel = $this->channels[$name] ?? null;

        return [
            'stream' => $channel->stream ?? $this->stream,
            'level' => $channel->level ?? $this->level,
            'json' => $channel->json ?? $this->json,
            'rotate' => $channel->rotate ?? ($channel?->stream === null ? $this->rotate : null),
            'syslog' => $channel?->syslog,
        ];
    }

    /**
     * @return list<string> the default channel first, then the named ones
     */
    public function names(): array
    {
        return array_values(array_unique([$this->channel, ...array_keys($this->channels)]));
    }
}
