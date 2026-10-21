<?php

declare(strict_types=1);

namespace Keel\Health;

final readonly class HealthResult
{
    /**
     * @param array<string, mixed> $data extra fields exposed in the report (latency, version, ...)
     */
    public function __construct(public HealthStatus $status, public ?string $detail = null, public array $data = []) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function up(?string $detail = null, array $data = []): self
    {
        return new self(HealthStatus::Up, $detail, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function degraded(string $detail, array $data = []): self
    {
        return new self(HealthStatus::Degraded, $detail, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function down(string $detail, array $data = []): self
    {
        return new self(HealthStatus::Down, $detail, $data);
    }
}
