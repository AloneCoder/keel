<?php

declare(strict_types=1);

namespace Keel\Log;

/**
 * One named channel of LoggerConfig. Every setting is optional and falls back to the defaults
 * of the config: new ChannelConfig(stream: 'var/log/audit.log', level: 'info', rotate: 'daily').
 */
final readonly class ChannelConfig
{
    /**
     * @param string|null $stream php://stderr, php://stdout or a file path
     * @param string|null $level  minimum PSR-3 level
     * @param bool|null   $json   JSON lines or text
     * @param string|null $rotate "daily" rotates a file stream by date (name-YYYY-MM-DD.log); null keeps one file
     * @param string|null $syslog send to the local syslog under this ident instead of a stream
     */
    public function __construct(
        public ?string $stream = null,
        public ?string $level = null,
        public ?bool $json = null,
        public ?string $rotate = null,
        public ?string $syslog = null,
    ) {
        if ($rotate !== null && $rotate !== 'daily') {
            throw new \InvalidArgumentException(sprintf('Log rotation must be "daily" or null, got "%s"', $rotate));
        }
    }
}
