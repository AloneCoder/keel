<?php

declare(strict_types=1);

namespace Keel\App\Doctor;

/**
 * One line of the keel:doctor report: what was checked, how it went, and (for problems) the
 * multi-line detail such as a container resolution path.
 */
final readonly class Finding
{
    public function __construct(
        public Severity $severity,
        public string $section,
        public string $message,
        public ?string $detail = null,
    ) {}

    public static function ok(string $section, string $message): self
    {
        return new self(Severity::Ok, $section, $message);
    }

    public static function warning(string $section, string $message, ?string $detail = null): self
    {
        return new self(Severity::Warning, $section, $message, $detail);
    }

    public static function failure(string $section, string $message, ?string $detail = null): self
    {
        return new self(Severity::Failure, $section, $message, $detail);
    }
}
