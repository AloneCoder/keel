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
        $session = $this->load(is_string($cookie) ? $cookie : null);

        $response = $handler->handle($request->withAttribute(Session::class, $session));

        return $this->persist($session, $request, $response);
    }

    private function load(?string $cookie): Session
    {
        if ($cookie === null) {
            return new Session(Session::generateId());
        }

        if ($this->storage instanceof CookieStorage) {
            $data = $this->storage->read($cookie);

            return $data === null ? new Session(Session::generateId()) : new Session(Session::generateId(), $data, false);
        }

        $id = $this->signer->verify($cookie);
        if ($id === null) {
            return new Session(Session::generateId());
        }
        $data = $this->storage->read($id);

        return $data === null ? new Session(Session::generateId()) : new Session($id, $data, false);
    }

    private function persist(Session $session, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($session->isDestroyed() || ($session->isChanged() && $session->all() === [] && !$session->isNew())) {
            if (!$this->storage instanceof CookieStorage) {
                $this->storage->destroy($session->id());
            }

            return $response->withAddedHeader('Set-Cookie', $this->cookie('', $request, -1));
        }

        if (!$session->isChanged()) {
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
        $secure = $this->config->secure ?? $request->getUri()->getScheme() === 'https';
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
