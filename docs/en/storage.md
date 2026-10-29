# File storage

Namespace `Keel\Storage`. A small `Storage` interface over a directory on disk (`LocalStorage`)
or anything league/flysystem reaches (`FlysystemStorage`: S3, SFTP, Google Cloud, in-memory), a
`Disks` registry naming several of them, and `#[FromFile]` to bind uploads to handler
parameters with a `#[File]` rule for size, type and extension.

```php
// config/storage.php
return static fn(string $projectDir): StorageConfig => new StorageConfig(
    disks: [
        'local' => DiskConfig::local($projectDir . '/var/storage'),
        'public' => DiskConfig::local($projectDir . '/public/uploads', url: '/uploads'),
        's3' => DiskConfig::flysystem(S3Filesystem::class, url: 'https://cdn.example.com'),
    ],
    default: 'local',
);

// config/services.php
return new ServicesConfig([StorageProvider::class]);
```

```php
final class AvatarController
{
    public function __construct(private readonly Disks $disks) {}

    #[Post('/me/avatar')]
    #[Auth]
    public function upload(
        Principal $user,
        #[FromFile, File(maxSize: '2M', mimeTypes: ['image/png', 'image/jpeg'])] UploadedFileInterface $avatar,
    ): array {
        // the extension comes from the checked content, never from the client's file name
        $path = sprintf('avatars/%s.%s', $user->id(), File::sniff($avatar) === 'image/png' ? 'png' : 'jpg');
        $this->disks->disk('public')->upload($avatar, $path);

        return ['url' => $this->disks->disk('public')->url($path)];
    }
}
```

## Storage

| Method | What it does |
|---|---|
| `write($path, $contents)` | a string or a readable stream; `LocalStorage` writes atomically (temporary file, then rename) |
| `upload(UploadedFileInterface $file, $path)` | moves an upload (streams it); a failed upload is a `StorageException` |
| `read($path)`, `readStream($path)` | the contents, or a stream the caller closes; a missing file is a `StorageException` |
| `exists`, `delete`, `move`, `size`, `lastModified` | deleting a missing file is not an error |
| `url($path)` | the public URL under the disk's base URL; a `StorageException` on a disk without one |

Paths are relative and `/`-separated; `..`, absolute paths, drive letters and NUL bytes are
refused (`StorageException`), so a path built from request data cannot leave the root. Missing
directories are created on write. A local disk with a `url` is served by the web server, so it
refuses names the server could execute or obey: `.php`, `.phtml`, `.phar` (also inside a name,
`x.php.png`, which Apache's `AddHandler` runs), `.htaccess` and `.user.ini`. Take the extension of
an upload from its checked content (`File::sniff()`), never from the client's file name: a
`.html` or `.svg` from a client is stored XSS on your own origin.

`Storage` in the container is the default disk; `Disks::disk('name')` picks another. A
Flysystem disk points at the container id of a `League\Flysystem\FilesystemOperator` you build
in a provider (`composer require league/flysystem` plus the adapter); `FlysystemStorage` wraps
one directly as well. Every disk is built on first use and kept for the worker's life.

## Uploads

`#[FromFile] UploadedFileInterface $avatar` takes the multipart field named like the parameter
(`#[FromFile('photo')]` names it). A nullable parameter is `null` without the field; a
non-nullable one answers 400 `Missing file "avatar"`. An upload PHP rejected (size limit,
partial) is 400 with the error code. An `array` parameter receives every file of a multi-file
field (`photos[]`). Validation rules on the parameter run on each file:

`#[File(maxSize: '2M', mimeTypes: ['image/png'], extensions: ['png'])]`: size in bytes or
`512K`/`2M`/`1G`; MIME types sniffed from the first bytes of the content with `finfo`, never from
the client's `Content-Type`; extensions of the client file name, case-insensitive. The client
file name is for display and extension checks only: build the stored path yourself.

The OpenAPI document describes such an operation with a `multipart/form-data` request body:
one `string`/`binary` property per file (an array of them for a multi-file field), required
when the parameter is, with the fields of a `#[FromBody]` DTO beside them.

`Input::file('avatar')` and `Input::files()` read uploads without an attribute, see
[HTTP and kernel](http.md#input).
