<?php

declare(strict_types=1);

namespace Keel\Http\Middleware;

use Keel\Http\Attribute\BodyLimit;
use Keel\Http\Exception\ContentTooLargeException;
use Keel\Http\Exception\UnsupportedMediaTypeException;
use Keel\Http\Stream\LimitedStream;
use Keel\Routing\RouteMatch;
use Keel\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Refuses request bodies that are too large (413) or of a media type the route does not take
 * (415). A Content-Length above the limit is refused before anything is read; a body without
 * one (chunked) is wrapped so that reading past the limit throws 413 instead of filling memory.
 * Requests without a body pass untouched.
 *
 * As global middleware it applies BodyLimitConfig, merged with the #[BodyLimit] of the route the
 * request is headed for (found with a cheap match against the compiled routes, so a route can
 * raise the limit); added by #[BodyLimit] alone it applies that route's settings.
 */
final class BodyLimitMiddleware implements MiddlewareInterface
{
    private readonly BodyLimitConfig $config;

    public function __construct(?BodyLimitConfig $config = null, private readonly ?Router $router = null)
    {
        $this->config = $config ?? new BodyLimitConfig();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // A request without Content-Length or Transfer-Encoding has no body (RFC 9112 §6.3), unless
        // the stream says otherwise (RoadRunner and tests hand over a body string).
        $length = $request->getHeaderLine('Content-Length');
        $size = $request->getBody()->getSize();
        if (!$request->hasHeader('Transfer-Encoding') && ($length === '' || $length === '0') && ($size === null || $size === 0)) {
            return $handler->handle($request);
        }
        if (!ctype_digit($length) && $size !== null) {
            $length = (string) $size;
        }

        $policy = $this->policyFor($request);
        if ($policy->contentTypes !== []) {
            $mediaType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));
            if (!$policy->accepts($mediaType)) {
                throw new UnsupportedMediaTypeException(sprintf(
                    'Content-Type %s is not accepted; send %s',
                    $mediaType === '' ? '(none)' : '"' . $mediaType . '"',
                    implode(', ', $policy->contentTypes),
                ));
            }
        }

        if ($policy->maxBytes > 0) {
            if (ctype_digit($length) && (int) $length > $policy->maxBytes) {
                throw new ContentTooLargeException(sprintf('Request body exceeds %d bytes', $policy->maxBytes));
            }
            $body = $request->getBody();
            $request = $request->withBody(new LimitedStream($body instanceof LimitedStream ? $body->inner() : $body, $policy->maxBytes));
        }

        return $handler->handle($request);
    }

    private function policyFor(ServerRequestInterface $request): BodyLimitConfig
    {
        $option = RouteMatch::of($request, $this->router)?->route->option(BodyLimit::KEY);

        return is_array($option) ? $this->config->merge($option) : $this->config;
    }
}
