<?php

declare(strict_types=1);

namespace Keel\Tests\Storage;

use Keel\Container\Container;
use Keel\Container\Definitions;
use Keel\Http\ArgumentResolver;
use Keel\Http\Attribute\FromFile;
use Keel\Kernel\Kernel;
use Keel\OpenApi\Generator;
use Keel\Routing\Router;
use Keel\Storage\DiskConfig;
use Keel\Storage\Disks;
use Keel\Storage\FlysystemStorage;
use Keel\Storage\LocalStorage;
use Keel\Storage\Path;
use Keel\Storage\Storage;
use Keel\Storage\StorageConfig;
use Keel\Storage\StorageException;
use Keel\Storage\StorageProvider;
use Keel\Testing\TestResponse;
use Keel\Tests\Storage\Fixtures\UploadController;
use Keel\Validation\File;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalStorage::class)]
#[CoversClass(FlysystemStorage::class)]
#[CoversClass(Path::class)]
#[CoversClass(DiskConfig::class)]
#[CoversClass(StorageConfig::class)]
#[CoversClass(Disks::class)]
#[CoversClass(StorageProvider::class)]
#[CoversClass(FromFile::class)]
#[CoversClass(File::class)]
#[CoversClass(ArgumentResolver::class)]
#[CoversClass(Generator::class)]
final class StorageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/storage-' . uniqid();
        mkdir($this->dir, 0o775, true);
    }

    /**
     * @return iterable<string, array{0: Storage}>
     */
    private function disks(): iterable
    {
        yield 'local' => [new LocalStorage($this->dir . '/local', 'https://cdn.test/files/')];
        yield 'flysystem' => [new FlysystemStorage(new Filesystem(new LocalFilesystemAdapter($this->dir . '/fly')), 'https://cdn.test/files')];
    }

    public function testEveryDiskBehavesTheSame(): void
    {
        foreach ($this->disks() as $name => [$disk]) {
            $disk->write('docs/a b/hello.txt', 'hello');
            self::assertTrue($disk->exists('docs/a b/hello.txt'), $name);
            self::assertSame('hello', $disk->read('docs/a b/hello.txt'), $name);
            $stream = $disk->readStream('docs/a b/hello.txt');
            self::assertSame('hello', stream_get_contents($stream), $name);
            fclose($stream);
            self::assertSame(5, $disk->size('docs/a b/hello.txt'), $name);
            self::assertEqualsWithDelta(time(), $disk->lastModified('docs/a b/hello.txt'), 5, $name);
            self::assertSame('https://cdn.test/files/docs/a%20b/hello.txt', $disk->url('docs/a b/hello.txt'), $name);

            $source = fopen('php://temp', 'r+b');
            self::assertNotFalse($source);
            fwrite($source, 'streamed');
            rewind($source);
            $disk->write('s.bin', $source);
            fclose($source);
            self::assertSame('streamed', $disk->read('s.bin'), $name);

            $disk->move('s.bin', 'moved/s.bin');
            self::assertFalse($disk->exists('s.bin'), $name);
            self::assertSame('streamed', $disk->read('moved/s.bin'), $name);
            $disk->delete('moved/s.bin');
            $disk->delete('moved/s.bin');
            self::assertFalse($disk->exists('moved/s.bin'), $name);

            $upload = new UploadedFile(Stream::create('data'), 4, \UPLOAD_ERR_OK, 'u.txt', 'text/plain');
            $disk->upload($upload, 'uploads/u.txt');
            self::assertSame('data', $disk->read('uploads/u.txt'), $name);

            try {
                $disk->read('missing.txt');
                self::fail($name . ': missing file read');
            } catch (StorageException) {
            }
            try {
                $disk->write('../escape.txt', 'x');
                self::fail($name . ': traversal accepted');
            } catch (StorageException) {
            }
            try {
                $disk->upload(new UploadedFile(Stream::create('x'), 1, \UPLOAD_ERR_PARTIAL), 'p.txt');
                self::fail($name . ': failed upload accepted');
            } catch (StorageException) {
            }
        }

        try {
            new LocalStorage($this->dir)->url('a.txt');
            self::fail('url without base accepted');
        } catch (StorageException) {
        }
        $this->expectException(StorageException::class);
        new LocalStorage('');
    }

    public function testPathNormalization(): void
    {
        self::assertSame('a/b/c.txt', Path::normalize('/a//./b\\c.txt'));
        self::assertSame('https://x/a%20b/c%3Fd', Path::url('https://x/', 'a b/c?d'));
        foreach (['', '/', '..', 'a/../../b', "a\0b", 'C:/x'] as $bad) {
            try {
                Path::normalize($bad);
                self::fail(sprintf('"%s" accepted', $bad));
            } catch (StorageException) {
            }
        }
    }

    public function testDisksAreBuiltFromTheConfigAndTheContainer(): void
    {
        $definitions = new Definitions();
        $definitions->instance(StorageConfig::class, new StorageConfig([
            'local' => DiskConfig::local($this->dir . '/d1'),
            'public' => DiskConfig::local($this->dir . '/d2', '/uploads'),
            'fly' => DiskConfig::flysystem('fs'),
            'broken' => DiskConfig::flysystem('nope'),
        ], 'public'));
        $definitions->instance('fs', new Filesystem(new LocalFilesystemAdapter($this->dir . '/d3')));
        $definitions->instance('nope', new \stdClass());
        new StorageProvider()->register($definitions);
        $container = new Container($definitions);

        $disks = $container->get(Disks::class);
        self::assertSame(['local', 'public', 'fly', 'broken'], $disks->names());
        self::assertSame($container->get(Storage::class), $disks->default());
        self::assertSame('/uploads/x.png', $disks->default()->url('x.png'));
        self::assertInstanceOf(LocalStorage::class, $disks->disk('local'));
        self::assertInstanceOf(FlysystemStorage::class, $disks->disk('fly'));
        self::assertSame($disks->disk('fly'), $disks->disk('fly'));
        self::assertInstanceOf(FilesystemOperator::class, $container->get('fs'));

        try {
            $disks->disk('other');
            self::fail('unknown disk accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('configured: local, public, fly, broken', $e->getMessage());
        }
        try {
            $disks->disk('broken');
            self::fail('non-filesystem service accepted');
        } catch (\LogicException $e) {
            self::assertStringContainsString('FilesystemOperator', $e->getMessage());
        }
        try {
            new StorageConfig([]);
            self::fail('no disks accepted');
        } catch (\InvalidArgumentException) {
        }
        try {
            DiskConfig::local('');
            self::fail('empty root accepted');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        new StorageConfig(['a' => DiskConfig::local('/tmp')], 'b');
    }

    public function testFileRule(): void
    {
        self::assertSame(2 * 1024 ** 2, File::bytes('2M'));
        self::assertSame(512 * 1024, File::bytes('512k'));
        self::assertSame(3, File::bytes('3'));
        self::assertSame(7, File::bytes(7));

        $rule = new File(maxSize: 10, mimeTypes: ['text/plain'], extensions: ['.TXT']);
        self::assertNull($rule->check('not a file'));
        self::assertNull($rule->check(new UploadedFile(Stream::create('hello'), 5, \UPLOAD_ERR_OK, 'a.txt')));
        self::assertSame('must be at most 10 bytes', $rule->check(new UploadedFile(Stream::create(str_repeat('a', 11)), 11, \UPLOAD_ERR_OK, 'a.txt')));
        self::assertSame('must be a txt file', $rule->check(new UploadedFile(Stream::create('hello'), 5, \UPLOAD_ERR_OK, 'a.png')));
        self::assertSame('must be of type text/plain', $rule->check(new UploadedFile(Stream::create("\x89PNG\r\n\x1a\n"), 8, \UPLOAD_ERR_OK, 'a.txt')));
        self::assertSame('upload failed', $rule->check(new UploadedFile(Stream::create('x'), 1, \UPLOAD_ERR_INI_SIZE, 'a.txt')));
        self::assertSame('must be at most 2M', new File('2M')->check(new UploadedFile(Stream::create('x'), 3 * 1024 ** 2, \UPLOAD_ERR_OK, 'a')));

        $this->expectException(\InvalidArgumentException::class);
        new File('lots');
    }

    public function testHandlersReceiveUploads(): void
    {
        $definitions = new Definitions();
        $definitions->instance(StorageConfig::class, new StorageConfig(['local' => DiskConfig::local($this->dir . '/app')]));
        new StorageProvider()->register($definitions);
        $kernel = Kernel::build($definitions, [UploadController::class]);
        $factory = new Psr17Factory();
        $send = static fn(string $path, array $files, ?string $json = null): TestResponse => new TestResponse($kernel->handle(
            new ServerRequest('POST', $path, $json === null ? [] : ['Content-Type' => 'application/json'], $json)->withUploadedFiles($files),
        ));

        $ok = new UploadedFile(Stream::create('hello world'), 11, \UPLOAD_ERR_OK, 'me.txt', 'text/plain');
        $send('/avatar', ['avatar' => $ok])->assertOk()->assertJson(['stored' => true, 'cover' => null]);
        self::assertFileExists($this->dir . '/app/avatars/me.txt');

        $send('/avatar', ['avatar' => new UploadedFile(Stream::create('hello world'), 11, \UPLOAD_ERR_OK, 'me.txt'), 'cover' => new UploadedFile(Stream::create('c'), 1, \UPLOAD_ERR_OK, 'c.jpg')])->assertOk()->assertJson(['cover' => 'c.jpg']);
        $send('/avatar', [])->assertProblem(400, 'Missing file "avatar"');
        $send('/avatar', ['avatar' => new UploadedFile(Stream::create('x'), 1, \UPLOAD_ERR_FORM_SIZE, 'a.txt')])->assertProblem(400, 'Invalid request data', ['avatar: upload failed (error 2)']);
        $send('/avatar', ['avatar' => new UploadedFile(Stream::create('x'), 1, \UPLOAD_ERR_OK, 'a.png')])->assertProblem(400, 'Invalid request data', ['avatar: must be a txt file']);
        $send('/avatar', ['avatar' => new UploadedFile(Stream::create(str_repeat('a', 2000)), 2000, \UPLOAD_ERR_OK, 'a.txt')])->assertProblem(400, 'Invalid request data', ['avatar: must be at most 1K']);

        $two = [new UploadedFile(Stream::create('1'), 1, \UPLOAD_ERR_OK, 'one.jpg'), new UploadedFile(Stream::create('2'), 1, \UPLOAD_ERR_OK, 'two.jpg')];
        $send('/photos', ['photos' => $two], '{"name":"Ann","email":"a@b.c"}')->assertOk()->assertJson(['count' => 2, 'names' => ['one.jpg', 'two.jpg'], 'user' => 'Ann']);
        $send('/photos', [], '{"name":"Ann","email":"a@b.c"}')->assertOk()->assertJson(['count' => 0]);
    }

    public function testOpenApiDocumentsUploadsAsMultipart(): void
    {
        $document = new Generator(Router::fromControllers(UploadController::class))->generate();
        $paths = (array) $document['paths'];
        $avatar = self::node($paths, '/avatar', 'post', 'requestBody', 'content', 'multipart/form-data', 'schema');
        self::assertSame(['avatar' => ['type' => 'string', 'format' => 'binary'], 'cover' => ['type' => 'string', 'format' => 'binary']], $avatar['properties']);
        self::assertSame(['avatar'], $avatar['required']);

        $photos = self::node($paths, '/photos', 'post', 'requestBody', 'content', 'multipart/form-data', 'schema');
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string', 'format' => 'binary']], self::node($photos, 'properties', 'photos'));
        self::assertArrayHasKey('name', self::node($photos, 'properties'), 'the DTO fields join the multipart schema');
        self::assertContains('name', (array) $photos['required']);
    }

    /**
     * @param array<mixed> $doc
     *
     * @return array<string, mixed>
     */
    private static function node(array $doc, string ...$keys): array
    {
        $value = $doc;
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value, 'missing ' . implode('.', $keys));
            $value = $value[$key];
        }
        self::assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }
}
