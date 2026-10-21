<?php

declare(strict_types=1);

namespace Keel\Kernel;

use Keel\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Outermost middleware: converts every throwable into a Problem Details response.
 * Server errors (5xx) are logged; in debug mode the payload carries the exception.
 */
final class ExceptionHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Responder $responder,
        private readonly bool $debug = false,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (\Throwable $exception) {
            return $this->render($exception, $request);
        }
    }

    public function render(\Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        $problem = ProblemDetails::fromThrowable($exception, $this->debug);

        if ($problem->status >= 500) {
            $this->logger?->error($exception->getMessage(), [
                'exception' => $exception,
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
            ]);
        }

        $response = $this->responder->json($problem->body, $problem->status, 'application/problem+json');
        foreach ($problem->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
