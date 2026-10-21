<?php

declare(strict_types=1);

namespace Keel\Http\Exception;

final class MethodNotAllowedException extends HttpException
{
    /**
     * @param list<string> $allowed
     */
    public function __construct(public readonly array $allowed, ?\Throwable $previous = null)
    {
        parent::__construct(405, '', ['Allow' => implode(', ', $allowed)], [], $previous);
    }
}
