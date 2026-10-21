# OpenAPI

Namespace `Keel\OpenApi`. The routes, the `#[FromQuery]` and `#[FromBody]` DTOs, the validation
rules, `#[Status]`, return types, `#[Auth]` and `#[RateLimit]` already describe the API; the
generator turns them into an OpenAPI 3.1 document without a second description to keep in sync.

## Serving the document

Add `OpenApiMiddleware` to `HttpConfig` and, optionally, `config/openapi.php`:

```php
return new OpenApiConfig(
    title: 'Orders API',
    version: '2.3.0',
    description: 'Internal order management',
    servers: [['url' => 'https://api.example.com']],
    specPath: '/openapi.json',     // null disables
    docsPath: '/docs',             // HTML reference (Scalar) built on the spec; null disables
    problemDetails: true,          // document 400/401/403/429 Problem Details responses
    docsScript: OpenApiConfig::DEFAULT_DOCS_SCRIPT,   // the Scalar script the docs page loads, pinned to a version
);
```

The document is generated on the first request and kept for the life of the process: php-fpm
and the built-in server rebuild it per request, workers on restart (`keel:serve --watch`).
`keel:openapi` prints the same JSON, `keel:openapi --output=public/openapi.json` writes it, for
committing or feeding to client generators.

Both paths are answered before routing, so `#[Auth]` cannot protect them; restrict them in the
reverse proxy or disable them in production if the API is not public. The docs page loads the
Scalar reference UI from jsDelivr, pinned to a version; set `docsScript` to another version or
to a copy you host.

## What is derived

| Source | In the document |
|---|---|
| route path `/users/{id:\d+}` | path `/users/{id}`, a required path parameter typed from the handler argument, `pattern` from the constraint |
| `#[FromQuery] Filter $f` | one query parameter per constructor parameter of the DTO; required when no default and not nullable |
| `#[FromQuery] int $page = 1` | one query parameter |
| `#[FromBody] CreateUser $c` | `requestBody` with a `$ref` to the `CreateUser` component schema |
| DTO constructor parameter | property typed from the signature: scalars, nullable (`type: [string, null]`), unions (`oneOf`), backed and unit enums (`enum`), nested DTOs (`$ref`), `DateTimeInterface` (`date-time`), arrays with element types from `@param list<Item>` or `#[Field(items:)]` |
| validation rule | `Length` → `minLength`/`maxLength`, `Range` → `minimum`/`maximum`, `Count` → `minItems`/`maxItems`, `Pattern` → `pattern`, `Email` → `format: email`, `Url` → `format: uri`, `Choice` → `enum`, `NotBlank` → required |
| `#[Status(201)]` and the return type | the success response: a DTO → `$ref`, `list<Dto>` from `@return` → array of `$ref`, `string` → `text/html`, `void`/`null` → 204, `ResponseInterface` → no schema |
| `Page` return type | the pagination envelope (`items`, `page`, `perPage`, `total`, `pages`) with the element type from `@return Page<Dto>` or `#[Response(type: Page::class, items: Dto::class)]` |
| `#[Auth]` | `security` with every configured authenticator's scheme, plus 401 (and 403 with roles) |
| `#[RateLimit]` | a 429 response |
| body or parameters present | a 400 response with the `Problem` schema |

Operation ids come from the route name, else `<Controller><Method>` (`userShow`); tags from the
controller class name without `Controller`. Ids are unique in the document: a route with several
HTTP methods gets one per method (`syncGet`, `syncPost`), and a second route that would reuse an
id (two `UserController::show()` in different namespaces) gets a numeric suffix (`userShow2`).
Give those an explicit `#[Operation(operationId:)]` when the generated names matter to a client
generator. Element types from `@param list<Item>` and `@return list<Item>` are resolved like PHP
would: fully qualified names, then the file's `use` imports (aliases included), then the
declaring namespace.

## Saying more

```php
#[Prefix('/users')]
#[Operation(tags: ['Users'], description: 'User accounts')]      // class-level defaults
final class UserController
{
    #[Get('/{id}')]
    #[Operation(summary: 'Fetch one user', operationId: 'getUser')]
    #[Response(200, 'The user', UserView::class)]
    #[Response(404, 'No such user')]
    public function show(int $id): ResponseInterface { /* ... */ }

    #[Get('/export')]
    #[Operation(deprecated: true)]
    public function export(): string { /* ... */ }

    #[Get('/internal')]
    #[Hidden]                                                      // left out of the document
    public function internal(): array { /* ... */ }
}

final readonly class CreateUser
{
    /** @param list<Address> $addresses */
    public function __construct(
        #[NotBlank, Length(max: 80)]
        #[Field(description: 'Display name', example: 'Ann')]
        public string $name,
        #[Field(items: Address::class)]                            // or the @param above
        public array $addresses = [],
        #[Field(format: 'uuid', deprecated: true)]
        public ?string $legacyId = null,
    ) {}
}
```

- `#[Operation]`: summary, description, tags, operationId, deprecated. On the class it sets
  defaults for every route; method-level values win per field.
- `#[Response(status, description, type, items, contentType)]`, repeatable: documented responses
  are merged over the derived ones, so `#[Response(404)]` alone keeps the derived 200; declaring
  any 2xx replaces the derived success response. `type` is a DTO class, a scalar type name or `array`.
- `#[Field]` on a DTO constructor parameter or a `#[FromQuery]` scalar: description, example,
  format, element type of an array, deprecated.
- `#[Hidden]` on a method or a class leaves routes out.

`SchemaBuilder` and `Generator` are public: generate a document for a subset of routes
(`new Generator(Router::fromControllers(...))`) or build schemas for your own tooling.
