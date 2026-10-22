<?php

declare(strict_types=1);

namespace Keel\Validation;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Constraints on an uploaded file (a #[FromFile] parameter): maximum size ("2M", "512K" or
 * bytes), allowed MIME types sniffed from the content (not the client's claim) and allowed
 * extensions of the client file name.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class File implements Rule
{
    public ?int $maxBytes;

    /** @var list<string> */
    public array $mimeTypes;

    /** @var list<string> */
    public array $extensions;

    /**
     * @param int|string|null $maxSize    bytes, or "2M" / "512K" / "1G"
     * @param list<string>    $mimeTypes  e.g. ['image/png', 'image/jpeg']
     * @param list<string>    $extensions e.g. ['png', 'jpg'], compared case-insensitively
     */
    public function __construct(int|string|null $maxSize = null, array $mimeTypes = [], array $extensions = [])
    {
        $this->maxBytes = $maxSize === null ? null : self::bytes($maxSize);
        $this->mimeTypes = array_map(strtolower(...), $mimeTypes);
        $this->extensions = array_map(static fn(string $e): string => strtolower(ltrim($e, '.')), $extensions);
    }

    public function check(mixed $value): ?string
    {
        if (!$value instanceof UploadedFileInterface) {
            return null;
        }
        if ($value->getError() !== \UPLOAD_ERR_OK) {
            return 'upload failed';
        }

        $size = $value->getSize();
        if ($this->maxBytes !== null && $size !== null && $size > $this->maxBytes) {
            return sprintf('must be at most %s', self::format($this->maxBytes));
        }
        if ($this->extensions !== []) {
            $extension = strtolower(pathinfo((string) $value->getClientFilename(), \PATHINFO_EXTENSION));
            if (!in_array($extension, $this->extensions, true)) {
                return 'must be a ' . implode(', ', $this->extensions) . ' file';
            }
        }
        if ($this->mimeTypes !== []) {
            $type = self::sniff($value);
            if ($type === null || !in_array($type, $this->mimeTypes, true)) {
                return 'must be of type ' . implode(', ', $this->mimeTypes);
            }
        }

        return null;
    }

    /**
     * The MIME type from the first bytes of the content, null when it cannot be read.
     */
    public static function sniff(UploadedFileInterface $file): ?string
    {
        try {
            $stream = $file->getStream();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $head = $stream->read(8192);
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
        } catch (\Throwable) {
            return null;
        }
        $type = new \finfo(\FILEINFO_MIME_TYPE)->buffer($head);

        return is_string($type) ? strtolower($type) : null;
    }

    public static function bytes(int|string $size): int
    {
        if (is_int($size)) {
            return $size;
        }
        if (preg_match('/^(\d+)\s*([KMG])?B?$/i', trim($size), $m) !== 1) {
            throw new \InvalidArgumentException(sprintf('Unreadable size "%s": use bytes, "512K", "2M" or "1G"', $size));
        }
        $unit = strtoupper($m[2] ?? '');

        return (int) $m[1] * match ($unit) {
            'K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3, default => 1,
        };
    }

    private static function format(int $bytes): string
    {
        foreach (['G' => 1024 ** 3, 'M' => 1024 ** 2, 'K' => 1024] as $unit => $factor) {
            if ($bytes >= $factor && $bytes % $factor === 0) {
                return intdiv($bytes, $factor) . $unit;
            }
        }

        return $bytes . ' bytes';
    }
}
