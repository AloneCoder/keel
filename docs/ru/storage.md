# Файловое хранилище

Namespace `Keel\Storage`. Небольшой интерфейс `Storage` над каталогом на диске (`LocalStorage`)
или чем угодно, до чего дотягивается league/flysystem (`FlysystemStorage`: S3, SFTP, Google
Cloud, память), реестр `Disks` с именованными дисками и `#[FromFile]` для привязки загрузок к
параметрам хэндлера с правилом `#[File]` на размер, тип и расширение.

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
        // расширение берётся из проверенного содержимого, а не из имени файла клиента
        $path = sprintf('avatars/%s.%s', $user->id(), File::sniff($avatar) === 'image/png' ? 'png' : 'jpg');
        $this->disks->disk('public')->upload($avatar, $path);

        return ['url' => $this->disks->disk('public')->url($path)];
    }
}
```

## Storage

| Метод | Что делает |
|---|---|
| `write($path, $contents)` | строка или читаемый поток; `LocalStorage` пишет атомарно (временный файл, затем rename) |
| `upload(UploadedFileInterface $file, $path)` | перемещает загрузку (потоком); неудачная загрузка — `StorageException` |
| `read($path)`, `readStream($path)` | содержимое или поток, который закрывает вызывающий; отсутствующий файл — `StorageException` |
| `exists`, `delete`, `move`, `size`, `lastModified` | удаление отсутствующего файла — не ошибка |
| `url($path)` | публичный URL под базовым URL диска; на диске без него — `StorageException` |

Пути относительные, разделитель `/`; `..`, абсолютные пути, буквы дисков и NUL-байты
отклоняются (`StorageException`), так что путь из данных запроса не выйдет за корень.
Отсутствующие каталоги создаются при записи. Локальный диск с `url` раздаёт веб-сервер, поэтому
он отказывается писать имена, которые сервер мог бы выполнить или прочитать как настройки:
`.php`, `.phtml`, `.phar` (в том числе внутри имени, `x.php.png`, такое выполняет `AddHandler` в
Apache), `.htaccess` и `.user.ini`. Расширение загрузки берите из проверенного содержимого
(`File::sniff()`), а не из имени файла клиента: `.html` или `.svg` от клиента — это stored XSS
на вашем же origin.

`Storage` в контейнере — диск по умолчанию; `Disks::disk('name')` выбирает другой.
Flysystem-диск указывает на id сервиса `League\Flysystem\FilesystemOperator`, который вы
собираете в провайдере (`composer require league/flysystem` плюс адаптер); `FlysystemStorage`
оборачивает его и напрямую. Каждый диск создаётся при первом обращении и живёт вместе с воркером.

## Загрузки

`#[FromFile] UploadedFileInterface $avatar` берёт multipart-поле с именем параметра
(`#[FromFile('photo')]` задаёт другое). Nullable-параметр без поля получает `null`;
не-nullable отвечает 400 `Missing file "avatar"`. Загрузка, отклонённая PHP (превышен размер,
частичная), — 400 с кодом ошибки. Параметр `array` получает все файлы поля с несколькими
файлами (`photos[]`). Правила валидации на параметре выполняются для каждого файла:

`#[File(maxSize: '2M', mimeTypes: ['image/png'], extensions: ['png'])]`: размер в байтах или
`512K`/`2M`/`1G`; MIME-типы определяются по первым байтам содержимого через `finfo`, а не по
`Content-Type` клиента; расширения берутся из клиентского имени файла без учёта регистра.
Клиентское имя годится только для показа и проверки расширения: путь хранения стройте сами.

В документе OpenAPI такая операция описана с телом `multipart/form-data`: по свойству
`string`/`binary` на файл (массив для поля с несколькими файлами), обязательному, когда
обязателен параметр, рядом с полями DTO из `#[FromBody]`.

`Input::file('avatar')` и `Input::files()` читают загрузки без атрибута, см.
[HTTP и ядро](http.md#input).
