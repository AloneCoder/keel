<?php

declare(strict_types=1);

namespace Keel\OpenApi;

use Keel\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the OpenAPI document (JSON) and an HTML reference page built on it. The document is
 * generated on first request and kept for the life of the process: php-fpm and the built-in
 * server rebuild it per request, workers rebuild it on restart (keel:serve --watch).
 */
final class OpenApiMiddleware implements MiddlewareInterface
{
    private readonly OpenApiConfig $config;

    private ?string $document = null;

    public function __construct(
        private readonly Generator $generator,
        private readonly Responder $responder,
        ?OpenApiConfig $config = null,
    ) {
        $this->config = $config ?? new OpenApiConfig();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        if ($this->config->specPath !== null && $path === $this->config->specPath) {
            return $this->responder->text($this->document(), 200, 'application/json');
        }
        if ($this->config->docsPath !== null && $path === $this->config->docsPath && $this->config->specPath !== null) {
            return $this->responder->text(self::page($this->config->title, $this->config->specPath, $this->config->docsScript), 200, 'text/html; charset=utf-8');
        }

        return $handler->handle($request);
    }

    public function document(): string
    {
        if ($this->document === null) {
            $this->document = json_encode($this->generator->generate(), Responder::JSON_FLAGS | \JSON_PRETTY_PRINT);
        }

        return $this->document;
    }

    private static function page(string $title, string $specPath, string $script): string
    {
        $title = htmlspecialchars($title, \ENT_QUOTES | \ENT_HTML5);
        $spec = htmlspecialchars($specPath, \ENT_QUOTES | \ENT_HTML5);
        $script = htmlspecialchars($script, \ENT_QUOTES | \ENT_HTML5);

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$title}</title>
            </head>
            <body>
            <script id="api-reference" data-url="{$spec}"></script>
            <script src="{$script}"></script>
            </body>
            </html>

            HTML;
    }
}
