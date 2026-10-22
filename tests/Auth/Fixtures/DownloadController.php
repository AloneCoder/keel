<?php

declare(strict_types=1);

namespace Keel\Tests\Auth\Fixtures;

use Keel\Auth\Attribute\Signed;
use Keel\Routing\Attribute\Get;

final class DownloadController
{
    /** @return array<string, mixed> */
    #[Get('/downloads/{id:\d+}', name: 'downloads.show')]
    #[Signed]
    public function show(int $id): array
    {
        return ['download' => $id];
    }

    /** @return array<string, mixed> */
    #[Get('/files/{name}', name: 'files.show')]
    public function file(string $name): array
    {
        return ['file' => $name];
    }
}
