<?php

declare(strict_types=1);

namespace Keel\Kernel;

use Keel\Http\Responder;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Outermost middleware: converts every throwable into a Problem Details response, as JSON
 * (application/problem+json) or as an HTML page when the client prefers text/html.
 * Server errors (5xx) are logged; in debug mode the payload carries the exception.
 */
final class ExceptionHandlerMiddleware implements MiddlewareInterface
{
    /** @var LoggerInterface|false|null null = not looked up yet, false = container has no logger */
    private LoggerInterface|false|null $logger = null;

    public function __construct(
        private readonly Responder $responder,
        private readonly bool $debug = false,
        private readonly ?ContainerInterface $container = null,
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
        try {
            return $this->problem($exception, $request);
        } catch (\Throwable $failure) {
            // Rendering itself failed (an invalid header value on an HttpException, a stream that cannot
            // be created, ...): the worker must still answer, with the plainest 500 possible.
            $this->logger()?->critical('Error response could not be rendered: ' . $failure->getMessage(), [
                'exception' => $failure,
                'original' => $exception,
            ]);

            return $this->responder->text(
                '{"type":"about:blank","title":"Internal Server Error","status":500}',
                500,
                'application/problem+json',
            );
        }
    }

    private function problem(\Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        $problem = ProblemDetails::fromThrowable($exception, $this->debug);

        if ($problem->status >= 500) {
            $this->logger()?->error($exception->getMessage(), [
                'exception' => $exception,
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
            ]);
        }

        $response = ErrorPage::prefersHtml($request)
            ? $this->responder->text(ErrorPage::render($problem, $exception, $request, $this->debug), $problem->status, 'text/html; charset=utf-8')
            : $this->responder->text(json_encode($problem->body, ProblemDetails::JSON_FLAGS), $problem->status, 'application/problem+json');
        foreach ($problem->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * Resolved on the first server error, so the logger (and its interface) are not loaded at boot.
     */
    private function logger(): ?LoggerInterface
    {
        if ($this->logger === null) {
            $logger = $this->container?->has(LoggerInterface::class) === true ? $this->container->get(LoggerInterface::class) : null;
            $this->logger = $logger instanceof LoggerInterface ? $logger : false;
        }

        return $this->logger === false ? null : $this->logger;
    }
}
