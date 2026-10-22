<?php

declare(strict_types=1);

namespace Keel\Http\Attribute;

/**
 * Fills the parameter from a multipart upload: #[FromFile] UploadedFileInterface $avatar
 * (the field named like the parameter, or #[FromFile('photo')]). A nullable parameter is null
 * without the field; a non-nullable one answers 400. An upload that failed (size limit, partial)
 * is 400 as well. An array parameter receives every file of a multi-file field. Validation
 * rules on the parameter (#[File(maxSize: '2M', mimeTypes: ['image/png'])]) run on each file.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class FromFile
{
    /**
     * @param string|null $name field name; the parameter's name when null
     */
    public function __construct(public ?string $name = null) {}
}
