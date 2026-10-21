<?php

declare(strict_types=1);

namespace Keel\Kernel;

use Keel\Http\Exception\HttpException;
use Keel\Http\Responder;

/**
 * RFC 9457 "Problem Details for HTTP APIs" payload built from an exception.
 */
final readonly class ProblemDetails
{
    /**
     * Responder::JSON_FLAGS plus substitution of invalid UTF-8: an exception message with stray
     * bytes (a driver error, binary input echoed back) must not make the error response itself fail.
     */
    public const int JSON_FLAGS = Responder::JSON_FLAGS | \JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     */
    private function __construct(public int $status, public array $headers, public array $body) {}

    public static function fromThrowable(\Throwable $exception, bool $debug): self
    {
        $http = $exception instanceof HttpException ? $exception : null;
        $status = $http !== null ? $http->status : 500;

        $body = [
            'type' => 'about:blank',
            'title' => HttpException::reasonPhrase($status),
            'status' => $status,
        ];

        if ($http !== null) {
            $body['detail'] = $http->getMessage();
            if ($http->errors !== []) {
                $body['errors'] = $http->errors;
            }
        } elseif ($debug) {
            $body['detail'] = $exception->getMessage();
        }

        if ($debug) {
            $body['exception'] = [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ];
        }

        return new self($status, $http !== null ? $http->headers : [], $body);
    }
}
