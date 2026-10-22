<?php

declare(strict_types=1);

namespace Keel\Session;

use Keel\Http\Exception\ForbiddenException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Synchronizer-token CSRF protection on top of the session. Every request gets a token in the
 * session (created on first sight) and a CsrfToken request attribute; a request with an unsafe
 * method (anything but GET, HEAD, OPTIONS, TRACE) must repeat it in the form field or the
 * header, or is answered 403. List it in HttpConfig after SessionMiddleware.
 *
 * The token lives for the session. To rotate it (after login, say) remove the session key and
 * the next request gets a fresh one.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    private readonly CsrfConfig $config;

    public function __construct(?CsrfConfig $config = null)
    {
        $this->config = $config ?? new CsrfConfig();
    }

    public static function token(ServerRequestInterface $request): ?CsrfToken
    {
        $token = $request->getAttribute(CsrfToken::class);

        return $token instanceof CsrfToken ? $token : null;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute(Session::class);
        if (!$session instanceof Session) {
            throw new \LogicException('CsrfMiddleware needs the session: list SessionMiddleware before it in HttpConfig');
        }

        $value = $session->get($this->config->key);
        if (!is_string($value) || preg_match('/^[a-f0-9]{40}$/', $value) !== 1) {
            $value = bin2hex(random_bytes(20));
            $session->set($this->config->key, $value);
        }

        if (!in_array($request->getMethod(), self::SAFE_METHODS, true) && !$this->exempt($request->getUri()->getPath())) {
            $submitted = $this->submitted($request);
            if ($submitted === null || !hash_equals($value, $submitted)) {
                throw new ForbiddenException('Invalid CSRF token');
            }
        }

        return $handler->handle($request->withAttribute(CsrfToken::class, new CsrfToken($value, $this->config->field, $this->config->header)));
    }

    private function exempt(string $path): bool
    {
        foreach ($this->config->except as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function submitted(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine($this->config->header);
        if ($header !== '') {
            return $header;
        }

        $body = $request->getParsedBody();
        $field = is_array($body) ? ($body[$this->config->field] ?? null) : null;

        return is_string($field) ? $field : null;
    }
}
