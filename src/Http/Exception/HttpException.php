<?php

declare(strict_types=1);

namespace Keel\Http\Exception;

/**
 * An exception that maps directly to an HTTP response. The message is safe to show to clients.
 */
class HttpException extends \RuntimeException
{
    /**
     * @param array<string, string> $headers
     * @param list<string>          $errors  per-field details, exposed as "errors" in Problem Details
     */
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly array $headers = [],
        public readonly array $errors = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : self::reasonPhrase($status), $status, $previous);
    }

    public static function reasonPhrase(int $status): string
    {
        return match ($status) {
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            307 => 'Temporary Redirect',
            308 => 'Permanent Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            409 => 'Conflict',
            410 => 'Gone',
            413 => 'Content Too Large',
            415 => 'Unsupported Media Type',
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            default => 'HTTP ' . $status,
        };
    }
}
