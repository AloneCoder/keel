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
     * $_SERVER keys that describe the current request rather than the environment. Under
     * php-fpm they would otherwise end up in the snapshot (and HTTP_* carries request headers).
     */
    private const array REQUEST_KEYS = [
        'AUTH_TYPE', 'CONTENT_LENGTH', 'CONTENT_TYPE', 'DOCUMENT_ROOT', 'DOCUMENT_URI', 'GATEWAY_INTERFACE',
        'HTTPS', 'ORIG_PATH_INFO', 'PATH_INFO', 'PATH_TRANSLATED', 'PHP_AUTH_DIGEST', 'PHP_AUTH_PW', 'PHP_AUTH_USER',
        'PHP_SELF', 'QUERY_STRING', 'REMOTE_ADDR', 'REMOTE_HOST', 'REMOTE_PORT', 'REMOTE_USER', 'REQUEST_METHOD',
        'REQUEST_SCHEME', 'REQUEST_TIME', 'REQUEST_TIME_FLOAT', 'REQUEST_URI', 'SCRIPT_FILENAME', 'SCRIPT_NAME',
        'SERVER_ADDR', 'SERVER_NAME', 'SERVER_PORT', 'SERVER_PROTOCOL', 'SERVER_SIGNATURE', 'SERVER_SOFTWARE',
    ];

    /**
     * Merges getenv(), $_ENV and $_SERVER (string values only); later sources win. From $_SERVER,
     * request variables (HTTP_*, REDIRECT_*, REQUEST_URI, ...) are taken only when getenv() or
     * $_ENV also has them.
     */
    public static function fromGlobals(): self
    {
        $values = [];
        foreach ([getenv(), $_ENV] as $source) {
            foreach ($source as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $values[$key] = $value;
                }
            }
        }

        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (!array_key_exists($key, $values) && self::isRequestKey($key)) {
                continue;
            }
            $values[$key] = $value;
        }

        return new self($values);
    }

    private static function isRequestKey(string $key): bool
    {
        return str_starts_with($key, 'HTTP_')
            || str_starts_with($key, 'REDIRECT_')
            || in_array($key, self::REQUEST_KEYS, true);
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
            $values[$key] = self::parseValue(trim($value));
        }

        return new self(($base !== null ? $base->values : []) + $values);
    }

    /**
     * Quoted values end at the matching quote; whatever follows (an inline comment) is dropped.
     * Unquoted values end at " #".
     */
    private static function parseValue(string $value): string
    {
        $quote = $value !== '' ? $value[0] : '';
        if ($quote === '"' || $quote === "'") {
            $closing = $quote === '"'
                ? (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $value, $match) === 1 ? $match[1] : null)
                : (($end = strpos($value, "'", 1)) !== false ? substr($value, 1, $end - 1) : null);
            if ($closing !== null) {
                return $quote === '"' ? stripcslashes($closing) : $closing;
            }
        }

        $comment = strpos($value, ' #');

        return $comment === false ? $value : rtrim(substr($value, 0, $comment));
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
