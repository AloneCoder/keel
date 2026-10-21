<?php

declare(strict_types=1);

namespace Keel\Session;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Loads the session from the cookie before the handler and persists it after. Cookie storage
 * keeps the data in the signed cookie itself; other storages keep a signed id in the cookie.
 * Handlers receive the session by type: public function profile(Session $session).
 *
 * The cookie is re-sent when the session changed, and also when the storage implements
 * SessionExpiry and less than half of the ttl is left, so an active user is never logged out
 * for lack of writes.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    private readonly SessionStorageInterface $storage;

    private readonly CookieSigner $signer;

    public function __construct(private readonly SessionConfig $config, ?SessionStorageInterface $storage = null)
    {
        $this->signer = new CookieSigner($config->secret);
        $this->storage = $storage ?? match ($config->storage) {
            'cookie' => new CookieStorage($this->signer),
            'file' => new FileStorage($config->filePath ?? sys_get_temp_dir() . '/keel-sessions'),
            default => throw new \InvalidArgumentException(sprintf('Unknown session storage "%s"', $config->storage)),
        };
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $cookie = $cookies[$this->config->cookie] ?? null;
        [$session, $refresh] = $this->load(is_string($cookie) ? $cookie : null);

        $response = $handler->handle($request->withAttribute(Session::class, $session));

        return $this->persist($session, $refresh, $request, $response);
    }

    /**
     * @return array{0: Session, 1: bool} the session and whether its expiry should be refreshed
     */
    private function load(?string $cookie): array
    {
        if ($cookie === null) {
            return [new Session(Session::generateId()), false];
        }

        if ($this->storage instanceof CookieStorage) {
            $payload = $this->storage->decode($cookie);

            return $payload === null
                ? [new Session(Session::generateId()), false]
                : [new Session(Session::generateId(), $payload['data'], false), $this->shouldRefresh($payload['exp'])];
        }

        $id = $this->signer->verify($cookie);
        if ($id === null) {
            return [new Session(Session::generateId()), false];
        }
        $data = $this->storage->read($id);
        if ($data === null) {
            return [new Session(Session::generateId()), false];
        }

        $expiresAt = $this->storage instanceof SessionExpiry ? $this->storage->expiresAt($id) : null;

        return [new Session($id, $data, false), $expiresAt !== null && $this->shouldRefresh($expiresAt)];
    }

    private function shouldRefresh(int $expiresAt): bool
    {
        return $expiresAt - time() < intdiv($this->config->ttl, 2);
    }

    private function persist(Session $session, bool $refresh, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($session->isDestroyed() || ($session->isChanged() && $session->all() === [] && !$session->isNew())) {
            if (!$this->storage instanceof CookieStorage) {
                $this->storage->destroy($session->id());
                if ($session->previousId() !== null) {
                    $this->storage->destroy($session->previousId());
                }
            }

            return $response->withAddedHeader('Set-Cookie', $this->cookie('', $request, -1));
        }

        if (!$session->isChanged() && !$refresh) {
            return $response;
        }

        $previous = $session->previousId();
        if ($previous !== null && !$this->storage instanceof CookieStorage) {
            $this->storage->destroy($previous);
        }

        if ($this->storage instanceof CookieStorage) {
            return $response->withAddedHeader('Set-Cookie', $this->cookie($this->storage->encode($session->all(), $this->config->ttl), $request, $this->config->ttl));
        }

        $this->storage->write($session->id(), $session->all(), $this->config->ttl);

        return $response->withAddedHeader('Set-Cookie', $this->cookie($this->signer->sign($session->id()), $request, $this->config->ttl));
    }

    private function cookie(string $value, ServerRequestInterface $request, int $maxAge): string
    {
        $secure = $this->config->sameSite === 'None' || ($this->config->secure ?? $request->getUri()->getScheme() === 'https');
        $parts = [
            $this->config->cookie . '=' . $value,
            'Path=' . $this->config->path,
            'HttpOnly',
            'SameSite=' . $this->config->sameSite,
        ];
        if ($this->config->domain !== null) {
            $parts[] = 'Domain=' . $this->config->domain;
        }
        if ($secure) {
            $parts[] = 'Secure';
        }
        $parts[] = $maxAge < 0 ? 'Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT' : 'Max-Age=' . $maxAge;

        return implode('; ', $parts);
    }
}
