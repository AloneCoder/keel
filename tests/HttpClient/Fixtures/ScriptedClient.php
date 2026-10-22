<?php

declare(strict_types=1);

namespace Keel\Tests\HttpClient\Fixtures;

use Keel\HttpClient\NetworkException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Answers from a script, one entry per call: a status code, a response or an exception
 * (a NetworkException is rethrown for the request actually sent).
 */
final class ScriptedClient implements ClientInterface
{
    /** @var list<string> request bodies as received, one per call */
    public array $bodies = [];

    /**
     * @param list<int|ResponseInterface|\Throwable> $script
     */
    public function __construct(private array $script) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->bodies[] = $request->getBody()->getContents();
        $next = array_shift($this->script) ?? throw new \LogicException('script exhausted');
        if ($next instanceof NetworkException) {
            throw new NetworkException($request, $next->getMessage());
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return is_int($next) ? new Response($next) : $next;
    }

    public function calls(): int
    {
        return count($this->bodies);
    }
}
