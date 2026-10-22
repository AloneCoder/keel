<?php

declare(strict_types=1);

namespace Keel\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Measurements of one request for the Server-Timing header (W3C Server Timing). Created per
 * request by ServerTimingMiddleware and stored as a request attribute, so a handler receives it
 * by type and passes it on:
 *
 *   public function show(int $id, ServerTiming $timing): array
 *   {
 *       $user = $timing->measure('db', fn() => $this->users->find($id), 'User lookup');
 *       ...
 *   }
 *
 * Browsers show the entries in the network panel next to the request.
 */
final class ServerTiming
{
    private const string TOKEN = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D';

    /** @var array<string, array{duration: float|null, description: string|null}> */
    private array $metrics = [];

    /** @var array<string, array{0: int|float, 1: string|null}> hrtime start and description of running timers */
    private array $running = [];

    /**
     * The collector the middleware attached to the request, or null without ServerTimingMiddleware.
     */
    public static function of(ServerRequestInterface $request): ?self
    {
        $timing = $request->getAttribute(self::class);

        return $timing instanceof self ? $timing : null;
    }

    public function start(string $name, ?string $description = null): void
    {
        self::assertName($name);
        $this->running[$name] = [hrtime(true), $description];
    }

    /**
     * Stops a timer started with start() and records it; returns the duration in milliseconds.
     * Stopping the same name again adds to the recorded duration.
     */
    public function stop(string $name): float
    {
        if (!isset($this->running[$name])) {
            throw new \LogicException(sprintf('Server timing "%s" was not started', $name));
        }
        [$started, $description] = $this->running[$name];
        unset($this->running[$name]);
        $duration = (hrtime(true) - $started) / 1e6;
        $this->add($name, ($this->metrics[$name]['duration'] ?? 0.0) + $duration, $description ?? $this->metrics[$name]['description'] ?? null);

        return $duration;
    }

    /**
     * Runs $work and records how long it took, also when it throws.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function measure(string $name, \Closure $work, ?string $description = null): mixed
    {
        $this->start($name, $description);
        try {
            return $work();
        } finally {
            $this->stop($name);
        }
    }

    /**
     * Records a metric directly: a duration in milliseconds, or none for a plain marker ("cache-miss").
     */
    public function add(string $name, ?float $milliseconds = null, ?string $description = null): void
    {
        self::assertName($name);
        if ($milliseconds !== null && $milliseconds < 0) {
            throw new \InvalidArgumentException('A server timing duration must not be negative');
        }
        $this->metrics[$name] = ['duration' => $milliseconds, 'description' => $description];
    }

    /**
     * @return array<string, array{duration: float|null, description: string|null}>
     */
    public function metrics(): array
    {
        return $this->metrics;
    }

    /**
     * The header value: 'db;dur=12.3;desc="User lookup", cache-miss'. Timers still running are left out.
     */
    public function header(): string
    {
        $entries = [];
        foreach ($this->metrics as $name => $metric) {
            $entries[] = self::entry($name, $metric['duration'], $metric['description']);
        }

        return implode(', ', $entries);
    }

    public static function entry(string $name, ?float $milliseconds, ?string $description = null): string
    {
        $entry = $name;
        if ($milliseconds !== null) {
            $entry .= ';dur=' . round($milliseconds, 3);
        }
        if ($description !== null && $description !== '') {
            // A quoted-string: control characters dropped, quote and backslash escaped.
            $clean = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $description);
            $entry .= ';desc="' . addcslashes($clean, '"\\') . '"';
        }

        return $entry;
    }

    private static function assertName(string $name): void
    {
        if (preg_match(self::TOKEN, $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Server timing name "%s" must be an HTTP token (letters, digits, -_.!#$%%&\'*+^`|~)', $name));
        }
    }
}
