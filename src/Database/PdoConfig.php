<?php

declare(strict_types=1);

namespace Keel\Database;

/**
 * Register one in config/database.php and load PdoProvider to get a shared \PDO.
 * Exceptions, associative fetches and native prepares are on by default.
 */
final readonly class PdoConfig
{
    /**
     * @param array<int, mixed> $options \PDO::ATTR_* overrides
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $dsn,
        public ?string $username = null,
        #[\SensitiveParameter]
        public ?string $password = null,
        public array $options = [],
    ) {}

    /**
     * @return array<int, mixed>
     */
    public function options(): array
    {
        return $this->options + [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
    }
}
