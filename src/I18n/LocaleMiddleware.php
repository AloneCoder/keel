<?php

declare(strict_types=1);

namespace Keel\I18n;

use Keel\Session\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Picks the locale of the request from the sources I18nConfig enables (path prefix, query
 * parameter, session, cookie, Accept-Language), stores it as the Locale request attribute, tells
 * a LocaleAware translator, and adds Content-Language (plus Vary: Accept-Language when the
 * header decided) to the response.
 */
final class LocaleMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly I18nConfig $config, private readonly ?Translator $translator = null) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        [$locale, $negotiated] = $this->resolve($request);
        if ($this->translator instanceof LocaleAware) {
            $this->translator->setLocale($locale);
        }

        $response = $handler->handle($request->withAttribute(Locale::class, new Locale($locale)));
        if (!$response->hasHeader('Content-Language')) {
            $response = $response->withHeader('Content-Language', $locale);
        }

        return $negotiated ? $response->withAddedHeader('Vary', 'Accept-Language') : $response;
    }

    /**
     * @return array{0: string, 1: bool} the locale and whether Accept-Language decided
     */
    private function resolve(ServerRequestInterface $request): array
    {
        if ($this->config->fromPath) {
            $segment = explode('/', ltrim($request->getUri()->getPath(), '/'), 2)[0];
            $locale = $segment === '' ? null : $this->config->match($segment);
            if ($locale !== null) {
                return [$locale, false];
            }
        }

        if ($this->config->queryParam !== null) {
            $value = $request->getQueryParams()[$this->config->queryParam] ?? null;
            $locale = is_string($value) ? $this->config->match($value) : null;
            if ($locale !== null) {
                return [$locale, false];
            }
        }

        if ($this->config->sessionKey !== null) {
            $session = $request->getAttribute(Session::class);
            $value = $session instanceof Session ? $session->get($this->config->sessionKey) : null;
            $locale = is_string($value) ? $this->config->match($value) : null;
            if ($locale !== null) {
                return [$locale, false];
            }
        }

        if ($this->config->cookie !== null) {
            $value = $request->getCookieParams()[$this->config->cookie] ?? null;
            $locale = is_string($value) ? $this->config->match($value) : null;
            if ($locale !== null) {
                return [$locale, false];
            }
        }

        if ($this->config->acceptLanguage) {
            $locale = $this->negotiate($request->getHeaderLine('Accept-Language'));
            if ($locale !== null) {
                return [$locale, true];
            }
        }

        return [$this->config->defaultLocale, false];
    }

    /**
     * The best supported locale of an Accept-Language header, by q and then by order.
     */
    public function negotiate(string $header): ?string
    {
        if (trim($header) === '') {
            return null;
        }
        $candidates = [];
        foreach (explode(',', $header) as $position => $entry) {
            $parts = array_map(trim(...), explode(';', $entry));
            $tag = array_shift($parts);
            $q = 1.0;
            foreach ($parts as $part) {
                if (preg_match('/^q=([0-9.]+)$/i', $part, $m) === 1) {
                    $q = (float) $m[1];
                }
            }
            if ($tag === '' || $q <= 0) {
                continue;
            }
            $locale = $tag === '*' ? $this->config->defaultLocale : $this->config->match($tag);
            if ($locale !== null) {
                $candidates[] = [$q, -$position, $locale];
            }
        }
        if ($candidates === []) {
            return null;
        }
        rsort($candidates);

        return $candidates[0][2];
    }
}
