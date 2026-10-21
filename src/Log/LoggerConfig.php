<?php

declare(strict_types=1);

namespace Keel\Log;

use Psr\Log\LogLevel;

/**
 * Settings of the built-in StreamLogger. Register one in config/logger.php to change them;
 * register your own LoggerInterface (Monolog, ...) in a ServiceProvider to replace the logger.
 */
final readonly class LoggerConfig
{
    /**
     * @param string $stream   php://stderr (default, what FrankenPHP and RoadRunner collect), php://stdout or a file path
     * @param string $level    minimum PSR-3 level to write
     * @param bool   $json     one JSON object per line, otherwise "[time] channel.LEVEL: message {context}"
     */
    public function __construct(
        public string $stream = 'php://stderr',
        public string $level = LogLevel::DEBUG,
        public string $channel = 'app',
        public bool $json = true,
    ) {}
}
