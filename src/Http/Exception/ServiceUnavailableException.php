<?php

declare(strict_types=1);

namespace Keel\Http\Exception;

final class ServiceUnavailableException extends HttpException
{
    /**
     * @param array<string, string> $headers
     * @param list<string>          $errors
     */
    public function __construct(string $message = '', array $headers = [], array $errors = [], ?\Throwable $previous = null)
    {
        parent::__construct(503, $message, $headers, $errors, $previous);
    }
}
