<?php

declare(strict_types=1);

namespace Keel\Config;

/**
 * Immutable snapshot of environment variables, read once at boot. Services never touch
 * $_ENV or getenv() themselves: in worker mode the environment is per process, not per request.
 */
final readonly class Env
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(private array $values) {}

    /**
     * Merges getenv(), $_ENV and $_SERVER (string values only); later sources win.
     */
    public static function fromGlobals(): self
    {
        $values = [];
        foreach ([getenv(), $_ENV, $_SERVER] as $source) {
            foreach ($source as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $values[$key] = $value;
                }
            }
        }

        return new self($values);
    }

    /**
     * Parses a dotenv file: KEY=value lines, optional quotes, # comments. No variable
     * expansion. Values already present in $base win over the file.
     */
    public static function fromFile(string $path, ?self $base = null): self
    {
        $values = [];
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Cannot read env file "%s"', $path));
        }

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim(preg_replace('/^export\s+/', '', $key) ?? $key);
            $value = trim($value);
            $quote = $value !== '' ? $value[0] : '';
            if (($quote === '"' || $quote === "'") && strlen($value) > 1 && str_ends_with($value, $quote)) {
                $value = substr($value, 1, -1);
                $value = $quote === '"' ? stripcslashes($value) : $value;
            } elseif (($comment = strpos($value, ' #')) !== false) {
                $value = rtrim(substr($value, 0, $comment));
            }

            $values[$key] = $value;
        }

        return new self(($base !== null ? $base->values : []) + $values);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function get(string $name, ?string $default = null): ?string
    {
        return $this->values[$name] ?? $default;
    }

    public function require(string $name): string
    {
        return $this->values[$name]
            ?? throw new \RuntimeException(sprintf('Environment variable "%s" is required', $name));
    }

    public function bool(string $name, bool $default = false): bool
    {
        $value = $this->values[$name] ?? null;
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $name, int $default = 0): int
    {
        $value = $this->values[$name] ?? null;

        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    public function with(string $name, string $value): self
    {
        return new self([$name => $value] + $this->values);
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->values;
    }
}
