<?php

declare(strict_types=1);

namespace Keel\Tests\Storage\Fixtures;

use Keel\Http\Attribute\FromBody;
use Keel\Http\Attribute\FromFile;
use Keel\Routing\Attribute\Post;
use Keel\Storage\Storage;
use Keel\Tests\Kernel\Fixtures\CreateUser;
use Keel\Validation\File;
use Psr\Http\Message\UploadedFileInterface;

final class UploadController
{
    public function __construct(private readonly Storage $storage) {}

    /** @return array<string, mixed> */
    #[Post('/avatar')]
    public function avatar(#[FromFile, File(maxSize: '1K', mimeTypes: ['text/plain'], extensions: ['txt'])] UploadedFileInterface $avatar, #[FromFile('cover')] ?UploadedFileInterface $cover = null): array
    {
        $this->storage->upload($avatar, 'avatars/' . $avatar->getClientFilename());

        return ['stored' => $this->storage->exists('avatars/' . $avatar->getClientFilename()), 'cover' => $cover?->getClientFilename()];
    }

    /**
     * @param list<UploadedFileInterface> $photos
     *
     * @return array<string, mixed>
     */
    #[Post('/photos')]
    public function photos(#[FromFile] array $photos, #[FromBody] CreateUser $user): array
    {
        return ['count' => count($photos), 'names' => array_map(static fn(UploadedFileInterface $f): ?string => $f->getClientFilename(), $photos), 'user' => $user->name];
    }
}
