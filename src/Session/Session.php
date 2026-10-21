<?php

declare(strict_types=1);

namespace Keel\Session;

/**
 * Per-request session data. Stored as a request attribute under Session::class and injected
 * into handlers by type; never kept in a service, so workers do not leak it between requests.
 */
final class Session
{
    private bool $changed = false;

    private bool $destroyed = false;

    private ?string $previousId = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private string $id, private array $data = [], private readonly bool $new = true) {}

    public function id(): string
    {
        return $this->id;
    }

    public function isNew(): bool
    {
        return $this->new;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->changed = true;
    }

    public function remove(string $key): void
    {
        if (array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
            $this->changed = true;
        }
    }

    /**
     * Reads and removes a value: flash messages.
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->remove($key);

        return $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    public function clear(): void
    {
        $this->data = [];
        $this->changed = true;
    }

    /**
     * New id, same data: call after login to defeat session fixation.
     */
    public function regenerate(): void
    {
        $this->previousId ??= $this->id;
        $this->id = self::generateId();
        $this->changed = true;
    }

    /**
     * The id before regenerate(), so the middleware can drop the old storage entry.
     */
    public function previousId(): ?string
    {
        return $this->previousId;
    }

    /**
     * Ends the session: storage entry and cookie are removed after the response.
     */
    public function destroy(): void
    {
        $this->data = [];
        $this->destroyed = true;
        $this->changed = true;
    }

    public function isChanged(): bool
    {
        return $this->changed;
    }

    public function isDestroyed(): bool
    {
        return $this->destroyed;
    }

    public static function generateId(): string
    {
        return bin2hex(random_bytes(20));
    }
}
