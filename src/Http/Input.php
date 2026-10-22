<?php

declare(strict_types=1);

namespace Keel\Http;

use Keel\Http\Exception\BadRequestException;
use Keel\Http\Middleware\TrustedProxiesMiddleware;
use Keel\Routing\RouteMatch;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Convenience reads over a PSR-7 request, injected into handlers by type:
 * public function search(Input $input). Query and body merge into one map (body wins); typed
 * readers cast like the hydrator and answer 400 on a mismatch. DTOs with #[FromQuery] and
 * #[FromBody] remain the typed way in; Input is for the odd parameter and for middleware.
 */
final class Input
{
    /** @var array<string, mixed>|null */
    private ?array $all = null;

    private readonly Hydrator $hydrator;

    public function __construct(
        private readonly ServerRequestInterface $request,
        private readonly ?RouteMatch $match = null,
        private readonly BodyParser $bodies = new BodyParser(),
        ?Hydrator $hydrator = null,
    ) {
        $this->hydrator = $hydrator ?? new Hydrator();
    }

    public function request(): ServerRequestInterface
    {
        return $this->request;
    }

    /**
     * Query parameters and the parsed body (JSON or form) as one map; a body field shadows a query parameter.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->all === null) {
            /** @var array<string, mixed> $query */
            $query = $this->request->getQueryParams();
            $this->all = array_replace($query, $this->method() === 'GET' || $this->method() === 'HEAD' ? [] : $this->bodies->parse($this->request));
        }

        return $this->all;
    }

    /**
     * One value by key, "a.b.c" reaching into nested arrays.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->all();
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function has(string $key): bool
    {
        $missing = new \stdClass();

        return $this->get($key, $missing) !== $missing;
    }

    /**
     * Present and not null, "" or [].
     */
    public function filled(string $key): bool
    {
        $value = $this->get($key);

        return $value !== null && $value !== '' && $value !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function only(string ...$keys): array
    {
        $all = $this->all();

        return array_intersect_key($all, array_flip($keys));
    }

    /**
     * @return array<string, mixed>
     */
    public function except(string ...$keys): array
    {
        return array_diff_key($this->all(), array_flip($keys));
    }

    public function string(string $key, ?string $default = null): ?string
    {
        $value = $this->get($key);

        return $value === null ? $default : $this->hydrator->string($value, $key);
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $value = $this->get($key);

        return $value === null ? $default : $this->hydrator->int($value, $key);
    }

    public function float(string $key, ?float $default = null): ?float
    {
        $value = $this->get($key);

        return $value === null ? $default : $this->hydrator->float($value, $key);
    }

    public function bool(string $key, ?bool $default = null): ?bool
    {
        $value = $this->get($key);

        return $value === null ? $default : $this->hydrator->bool($value, $key);
    }

    /**
     * @param array<mixed>|null $default
     *
     * @return array<mixed>|null
     */
    public function array(string $key, ?array $default = null): ?array
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        if (!is_array($value)) {
            throw new BadRequestException('Invalid request data', [], [$key . ': must be an array']);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function query(): array
    {
        /** @var array<string, mixed> $query */
        $query = $this->request->getQueryParams();

        return $query;
    }

    /**
     * A route placeholder value, decoded.
     */
    public function param(string $name, ?string $default = null): ?string
    {
        return $this->match?->params[$name] ?? $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->request->hasHeader($name) ? $this->request->getHeaderLine($name) : $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        $value = $this->request->getCookieParams()[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function file(string $name): ?UploadedFileInterface
    {
        $file = $this->request->getUploadedFiles()[$name] ?? null;

        return $file instanceof UploadedFileInterface ? $file : null;
    }

    /**
     * @return array<string, UploadedFileInterface>
     */
    public function files(): array
    {
        $files = [];
        foreach ($this->request->getUploadedFiles() as $name => $file) {
            if ($file instanceof UploadedFileInterface) {
                $files[(string) $name] = $file;
            }
        }

        return $files;
    }

    /**
     * The token of an "Authorization: Bearer ..." header, null when absent or another scheme.
     */
    public function bearerToken(): ?string
    {
        return preg_match('/^Bearer\s+(\S+)$/i', $this->request->getHeaderLine('Authorization'), $m) === 1 ? $m[1] : null;
    }

    /**
     * The client address as TrustedProxiesMiddleware established it, else the socket peer.
     */
    public function ip(): ?string
    {
        $ip = $this->request->getAttribute(TrustedProxiesMiddleware::CLIENT_IP);
        if (is_string($ip)) {
            return $ip;
        }
        $remote = $this->request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($remote) ? $remote : null;
    }

    public function method(): string
    {
        return $this->request->getMethod();
    }

    public function path(): string
    {
        $path = $this->request->getUri()->getPath();

        return $path === '' ? '/' : $path;
    }

    public function isSecure(): bool
    {
        return $this->request->getUri()->getScheme() === 'https';
    }

    /**
     * The request body is JSON (application/json or a +json type).
     */
    public function isJson(): bool
    {
        $type = strtolower(trim(explode(';', $this->request->getHeaderLine('Content-Type'), 2)[0]));

        return $type === 'application/json' || str_ends_with($type, '+json');
    }

    /**
     * The client prefers JSON: a JSON type is the first Accept entry, or Accept is absent
     * for an XMLHttpRequest / fetch call that sent JSON.
     */
    public function wantsJson(): bool
    {
        $accept = strtolower($this->request->getHeaderLine('Accept'));
        if ($accept === '') {
            return $this->isJson();
        }
        $first = trim(explode(';', explode(',', $accept, 2)[0], 2)[0]);

        return $first === 'application/json' || str_ends_with($first, '+json');
    }

    public function isAjax(): bool
    {
        return strtolower($this->request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
    }

}
