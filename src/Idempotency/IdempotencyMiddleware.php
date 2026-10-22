<?php

declare(strict_types=1);

namespace Keel\Idempotency;

use Keel\Auth\Authentication;
use Keel\Http\Exception\BadRequestException;
use Keel\Http\Exception\ConflictException;
use Keel\Http\Exception\UnprocessableContentException;
use Keel\Idempotency\Attribute\Idempotent;
use Keel\RateLimit\ApcuCounters;
use Keel\RateLimit\CounterStorage;
use Keel\RateLimit\InMemoryCounters;
use Keel\Routing\RouteMatch;
use Keel\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Idempotency-Key handling (IETF draft-ietf-httpapi-idempotency-key-header), for POST and PATCH
 * by default:
 *
 *   - the first request with a key runs, and its response (status below 500, not 429) is stored
 *     in the PSR-16 cache for $ttl seconds;
 *   - a repeat with the same key and the same request gets the stored response, marked
 *     Idempotent-Replayed: true, without running the handler;
 *   - the same key with a different method, path, query or body: 422;
 *   - a repeat while the first is still running: 409 with Retry-After (the lock is an atomic
 *     CounterStorage increment: APCu, Redis or process memory);
 *   - a key on a request that failed (5xx, an exception) is released, so a retry runs again.
 *
 * Keys are scoped to the authenticated principal, or else to the Authorization header, so one
 * client cannot replay another's response. A key is 1-255 visible ASCII characters; a quoted
 * structured-field string ("...") is accepted too. Streamed (non-seekable) responses are not stored.
 *
 * As global middleware it applies IdempotencyConfig to POST and PATCH, merged with the
 * #[Idempotent] of the route the request is headed for; added by #[Idempotent] alone it applies
 * that route's settings to any unsafe method.
 */
final class IdempotencyMiddleware implements MiddlewareInterface
{
    public const string REPLAYED = 'Idempotent-Replayed';

    /** Request attribute: an outer instance already handled the key. */
    private const string HANDLED = self::class . ':handled';

    private const string KEY_PATTERN = '/^[\x21-\x7E][\x20-\x7E]{0,254}$/D';

    private readonly IdempotencyConfig $config;

    private readonly CounterStorage $locks;

    private readonly ResponseFactoryInterface&StreamFactoryInterface $factory;

    public function __construct(
        private readonly CacheInterface $cache,
        ?IdempotencyConfig $config = null,
        ?CounterStorage $locks = null,
        private readonly ?Router $router = null,
    ) {
        $this->config = $config ?? new IdempotencyConfig();
        $this->locks = $locks ?? (ApcuCounters::available() ? new ApcuCounters('keel:idem:') : new InMemoryCounters());
        $this->factory = new Psr17Factory();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getAttribute(self::HANDLED) === true) {
            return $handler->handle($request);
        }

        $option = RouteMatch::of($request, $this->router)?->route->option(Idempotent::KEY);
        $settings = is_array($option) ? $this->config->merge($option) : $this->config;
        $method = $request->getMethod();
        $applies = is_array($option) ? !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) : in_array($method, $settings->methods, true);
        if (!$applies) {
            return $handler->handle($request);
        }

        $key = self::key($request->getHeaderLine($settings->header));
        if ($key === null) {
            if ($settings->required) {
                throw new BadRequestException(sprintf('This request needs an %s header', $settings->header));
            }

            return $handler->handle($request);
        }
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new BadRequestException(sprintf('%s must be 1-255 visible ASCII characters', $settings->header));
        }

        [$request, $body] = $this->readBody($request);
        $id = $settings->prefix . hash('xxh128', self::scope($request) . "\0" . $key);
        $fingerprint = hash('sha256', $method . ' ' . $request->getUri()->getPath() . '?' . $request->getUri()->getQuery() . "\n" . $body);

        $stored = $this->cache->get($id);
        if (is_array($stored) && is_string($stored['fingerprint'] ?? null)) {
            if (!hash_equals($stored['fingerprint'], $fingerprint)) {
                throw new UnprocessableContentException(sprintf('This %s was already used for a different request', $settings->header));
            }

            return $this->replay($stored);
        }

        // A released key starts a new epoch, so a retry after a failure takes a fresh lock.
        $epoch = $this->cache->get($id . '.epoch', 0);
        $epoch = is_int($epoch) ? $epoch : 0;
        if ($this->locks->increment(hash('xxh128', $id) . ':' . $epoch, $settings->lockTtl) > 1) {
            throw new ConflictException(sprintf('A request with this %s is still being processed', $settings->header), ['Retry-After' => '1']);
        }

        try {
            $response = $handler->handle($request->withAttribute(self::HANDLED, true));
        } catch (\Throwable $exception) {
            $this->cache->set($id . '.epoch', $epoch + 1, $settings->lockTtl);

            throw $exception;
        }

        $record = self::record($response, $fingerprint);
        if ($record === null) {
            $this->cache->set($id . '.epoch', $epoch + 1, $settings->lockTtl);
        } else {
            $this->cache->set($id, $record, $settings->ttl);
        }

        return $response;
    }

    /**
     * The key without surrounding quotes and whitespace; null when the header is absent or empty.
     */
    private static function key(string $header): ?string
    {
        $key = trim($header);
        if (strlen($key) >= 2 && $key[0] === '"' && $key[-1] === '"') {
            $key = substr($key, 1, -1);
        }

        return $key === '' ? null : $key;
    }

    private static function scope(ServerRequestInterface $request): string
    {
        $principal = Authentication::principal($request);
        if ($principal !== null) {
            return 'principal:' . $principal->id();
        }
        $authorization = $request->getHeaderLine('Authorization');

        return $authorization === '' ? '' : 'authorization:' . hash('sha256', $authorization);
    }

    /**
     * The body as a string for the fingerprint, and a request whose body the handler can still read.
     *
     * @return array{0: ServerRequestInterface, 1: string}
     */
    private function readBody(ServerRequestInterface $request): array
    {
        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
            $body = $stream->getContents();
            $stream->rewind();

            return [$request, $body];
        }
        $body = $stream->getContents();

        return [$request->withBody($this->factory->createStream($body)), $body];
    }

    /**
     * @return array{fingerprint: string, status: int, reason: string, headers: array<string, list<string>>, body: string}|null
     */
    private static function record(ResponseInterface $response, string $fingerprint): ?array
    {
        $status = $response->getStatusCode();
        $stream = $response->getBody();
        if ($status >= 500 || $status === 429 || !$stream->isSeekable()) {
            return null;
        }
        $stream->rewind();
        $body = $stream->getContents();
        $stream->rewind();
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = array_values(array_map(strval(...), $values));
        }

        return ['fingerprint' => $fingerprint, 'status' => $status, 'reason' => $response->getReasonPhrase(), 'headers' => $headers, 'body' => $body];
    }

    /**
     * @param array<mixed> $stored
     */
    private function replay(array $stored): ResponseInterface
    {
        $status = is_int($stored['status'] ?? null) ? $stored['status'] : 200;
        $reason = is_string($stored['reason'] ?? null) ? $stored['reason'] : '';
        $response = $this->factory->createResponse($status, $reason)
            ->withBody($this->factory->createStream(is_string($stored['body'] ?? null) ? $stored['body'] : ''));
        foreach (is_array($stored['headers'] ?? null) ? $stored['headers'] : [] as $name => $values) {
            if (is_string($name) && is_array($values)) {
                $response = $response->withHeader($name, array_values(array_filter($values, is_string(...))));
            }
        }

        return $response->withHeader(self::REPLAYED, 'true');
    }
}
