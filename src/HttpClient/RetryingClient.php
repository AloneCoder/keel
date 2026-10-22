<?php

declare(strict_types=1);

namespace Keel\HttpClient;

use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * PSR-18 decorator that repeats a request after a network failure or a transient status
 * (RetryConfig::$statuses), waiting with exponential backoff and jitter in between.
 *
 * Only requests that are safe to repeat are retried: an idempotent method, or any method with an
 * Idempotency-Key header; a body that cannot be rewound is never sent twice. A Retry-After on the
 * response replaces the backoff, up to RetryConfig::$maxDelay; a longer one returns the response
 * as is rather than holding the worker. An open circuit (CircuitOpenException) is not retried:
 * failing fast is the point of the breaker. The last attempt's response or exception is the result.
 */
final class RetryingClient implements ClientInterface
{
    private const string HTTP_DATE = 'D, d M Y H:i:s \G\M\T';

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /**
     * @param (\Closure(float): void)|null $sleep waits the given seconds; usleep() by default
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RetryConfig $config = new RetryConfig(),
        ?\Closure $sleep = null,
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $repeatable = $this->repeatable($request);
        for ($attempt = 1; ; $attempt++) {
            $final = !$repeatable || $attempt >= $this->config->maxAttempts;
            try {
                $response = $this->client->sendRequest($request);
            } catch (CircuitOpenException $e) {
                throw $e;
            } catch (NetworkExceptionInterface $e) {
                if ($final) {
                    throw $e;
                }
                $this->wait($request, $attempt, $this->backoff($attempt), $e->getMessage());
                continue;
            }

            $status = $response->getStatusCode();
            if ($final || !in_array($status, $this->config->statuses, true)) {
                return $response;
            }
            $delay = $this->retryAfter($response) ?? $this->backoff($attempt);
            if ($delay > $this->config->maxDelay) {
                return $response;
            }
            $this->wait($request, $attempt, $delay, 'status ' . $status);
        }
    }

    private function repeatable(RequestInterface $request): bool
    {
        $header = $this->config->idempotencyHeader;
        if (!in_array(strtoupper($request->getMethod()), $this->config->methods, true) && ($header === null || !$request->hasHeader($header))) {
            return false;
        }
        $body = $request->getBody();

        return $body->getSize() === 0 || $body->isSeekable();
    }

    private function backoff(int $attempt): float
    {
        $delay = $this->config->backoff($attempt);

        return $this->config->jitter && $delay > 0 ? new \Random\Randomizer()->getFloat($delay / 2, $delay, \Random\IntervalBoundary::ClosedClosed) : $delay;
    }

    /**
     * Seconds the response asks to wait (delta-seconds or an HTTP date), null without a usable Retry-After.
     */
    private function retryAfter(ResponseInterface $response): ?float
    {
        $value = trim($response->getHeaderLine('Retry-After'));
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (float) $value;
        }
        $date = \DateTimeImmutable::createFromFormat(self::HTTP_DATE, $value, new \DateTimeZone('UTC'));
        if ($date === false) {
            return null;
        }
        $now = $this->clock?->now() ?? new \DateTimeImmutable();

        return (float) max(0, $date->getTimestamp() - $now->getTimestamp());
    }

    private function wait(RequestInterface $request, int $attempt, float $delay, string $reason): void
    {
        $uri = $request->getUri()->withUserInfo('')->withQuery('')->withFragment('');
        $this->logger?->info('Retrying {method} {uri} in {delay} s after {reason} (attempt {attempt} of {max})', [
            'method' => $request->getMethod(),
            'uri' => (string) $uri,
            'delay' => round($delay, 3),
            'reason' => $reason,
            'attempt' => $attempt + 1,
            'max' => $this->config->maxAttempts,
        ]);
        if ($delay > 0) {
            ($this->sleep)($delay);
        }
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
    }
}
