<?php

declare(strict_types=1);

namespace Keel\Config;

/**
 * Core settings. Conventionally built in config/app.php from Env.
 */
final readonly class AppConfig
{
    public function __construct(
        public string $projectDir,
        public string $env = 'prod',
        public bool $debug = false,
        public ?string $cacheDir = null,
    ) {}

    public static function fromEnv(string $projectDir, Env $env): self
    {
        $name = $env->get('APP_ENV', 'prod') ?? 'prod';

        return new self($projectDir, $name, $env->bool('APP_DEBUG', $name === 'dev'));
    }

    public function cacheDir(): string
    {
        return $this->cacheDir ?? $this->projectDir . '/var/cache/' . $this->env;
    }
}
