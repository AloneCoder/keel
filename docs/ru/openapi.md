# OpenAPI

Пространство имён `Keel\OpenApi`. Маршруты, DTO из `#[FromQuery]` и `#[FromBody]`, правила
валидации, `#[Status]`, типы возврата, `#[Auth]` и `#[RateLimit]` уже описывают API; генератор
превращает их в документ OpenAPI 3.1 без второго описания, которое пришлось бы синхронизировать.

## Отдача документа

Добавьте `OpenApiMiddleware` в `HttpConfig` и, по желанию, `config/openapi.php`:

```php
return new OpenApiConfig(
    title: 'Orders API',
    version: '2.3.0',
    description: 'Внутреннее управление заказами',
    servers: [['url' => 'https://api.example.com']],
    specPath: '/openapi.json',     // null отключает
    docsPath: '/docs',             // HTML-справочник (Scalar) поверх спеки; null отключает
    problemDetails: true,          // документировать ответы 400/401/403/429 Problem Details
);
```

Документ генерируется при первом запросе и живёт, пока живёт процесс: php-fpm и встроенный
сервер пересобирают его на каждый запрос, воркеры — при перезапуске (`keel:serve --watch`).
`keel:openapi` печатает тот же JSON, `keel:openapi --output=public/openapi.json` записывает в файл
для коммита или генераторов клиентов.

## Что выводится

| Источник | В документе |
|---|---|
| путь маршрута `/users/{id:\d+}` | путь `/users/{id}`, обязательный path-параметр с типом из аргумента хэндлера, `pattern` из ограничения |
| `#[FromQuery] Filter $f` | по query-параметру на каждый параметр конструктора DTO; обязателен, если нет default и тип не nullable |
| `#[FromQuery] int $page = 1` | один query-параметр |
| `#[FromBody] CreateUser $c` | `requestBody` со ссылкой `$ref` на схему компонента `CreateUser` |
| параметр конструктора DTO | свойство с типом из сигнатуры: скаляры, nullable (`type: [string, null]`), union (`oneOf`), backed и unit enum (`enum`), вложенные DTO (`$ref`), `DateTimeInterface` (`date-time`), массивы с типом элементов из `@param list<Item>` или `#[Field(items:)]` |
| правило валидации | `Length` → `minLength`/`maxLength`, `Range` → `minimum`/`maximum`, `Count` → `minItems`/`maxItems`, `Pattern` → `pattern`, `Email` → `format: email`, `Url` → `format: uri`, `Choice` → `enum`, `NotBlank` → required |
| `#[Status(201)]` и тип возврата | успешный ответ: DTO → `$ref`, `list<Dto>` из `@return` → массив `$ref`, `string` → `text/html`, `void`/`null` → 204, `ResponseInterface` → без схемы |
| `#[Auth]` | `security` со схемой каждого настроенного аутентификатора, плюс 401 (и 403 при ролях) |
| `#[RateLimit]` | ответ 429 |
| есть тело или параметры | ответ 400 со схемой `Problem` |

Идентификаторы операций берутся из имени маршрута, иначе `<Контроллер><Метод>` (`userShow`);
теги — из имени класса контроллера без `Controller`.

## Уточнения

```php
#[Prefix('/users')]
#[Operation(tags: ['Users'], description: 'Учётные записи')]      // умолчания для класса
final class UserController
{
    #[Get('/{id}')]
    #[Operation(summary: 'Получить пользователя', operationId: 'getUser')]
    #[Response(200, 'Пользователь', UserView::class)]
    #[Response(404, 'Нет такого пользователя')]
    public function show(int $id): ResponseInterface { /* ... */ }

    #[Get('/export')]
    #[Operation(deprecated: true)]
    public function export(): string { /* ... */ }

    #[Get('/internal')]
    #[Hidden]                                                      // не попадает в документ
    public function internal(): array { /* ... */ }
}

final readonly class CreateUser
{
    /** @param list<Address> $addresses */
    public function __construct(
        #[NotBlank, Length(max: 80)]
        #[Field(description: 'Отображаемое имя', example: 'Ann')]
        public string $name,
        #[Field(items: Address::class)]                            // либо @param выше
        public array $addresses = [],
        #[Field(format: 'uuid', deprecated: true)]
        public ?string $legacyId = null,
    ) {}
}
```

- `#[Operation]`: summary, description, tags, operationId, deprecated. На классе задаёт умолчания
  для всех маршрутов; значения на методе побеждают по каждому полю.
- `#[Response(status, description, type, items, contentType)]`, повторяемый: объявленные ответы
  заменяют выведенный успешный ответ. `type` — класс DTO, имя скалярного типа или `array`.
- `#[Field]` на параметре конструктора DTO или скаляре `#[FromQuery]`: описание, пример, формат,
  тип элементов массива, deprecated.
- `#[Hidden]` на методе или классе исключает маршруты.

`SchemaBuilder` и `Generator` публичны: можно сгенерировать документ для части маршрутов
(`new Generator(Router::fromControllers(...))`) или строить схемы для своих инструментов.
