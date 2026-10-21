# HTTP и ядро

Namespaces `Keel\Http` и `Keel\Kernel`. PSR-7 сообщения — `nyholm/psr7`, PSR-15 middleware,
PSR-17 фабрики. Ядро — один объект на воркер, без состояния запроса.

## Конвейер запроса

```
ExceptionHandlerMiddleware → глобальные middleware → RoutingMiddleware
    → middleware маршрута (класс, затем метод) → контроллер → Responder
```

```php
$kernel = Kernel::build(
    $definitions,                         // Keel\Container\Definitions
    [UserController::class],              // контроллеры с атрибутами
    [CorsMiddleware::class],              // глобальные middleware, по порядку
    debug: $env === 'dev',
);
$response = $kernel->handle($request);    // ServerRequestInterface → ResponseInterface
$kernel->terminate();                     // после отправки ответа: Container::reset()
```

`Kernel::build()` регистрирует в контейнере `Psr17Factory` под всеми PSR-17 интерфейсами,
`Responder`, `ArgumentResolver` и `Router`, если приложение не сделало этого само. Для
скомпилированного контейнера и кэша маршрутов используйте конструктор
`new Kernel($container, $router, $middleware, $debug)`; `Kernel::registerDefaults()` нужно
вызвать перед компиляцией.

`Pipeline` — PSR-15 обработчик: middleware выполняются в порядке объявления (FIFO), классы
резолвятся из контейнера при первом использовании и кэшируются. Экземпляр конвейера не
хранит состояние запроса и переиспользуется между запросами.

## Аргументы хэндлера

`ArgumentResolver` заполняет параметры метода контроллера, для каждого параметра в этом порядке:

1. тип `ServerRequestInterface`, `RouteMatch` или `Route` — сам запрос или результат роутинга;
2. `#[FromQuery]` — DTO гидратируется из всех query-параметров; скаляр читается из параметра
   с именем аргумента (отсутствие без дефолта — 400);
3. `#[FromBody]` — JSON (`application/json`, `*+json`) или `getParsedBody()`; DTO
   гидратируется, `array` получает тело как есть; битый JSON — 400;
4. плейсхолдер маршрута с именем параметра, приведённый к типу (`int`, `float`, `bool`,
   `string`, enum); несоответствие — 400;
5. сервис из контейнера по типу (`has()`);
6. значение по умолчанию или `null` для nullable;
7. иначе `LogicException` (это ошибка конфигурации, ответ 500).

## Гидратор

`Hydrator::hydrate(Dto::class, array $data)` вызывает конструктор DTO с именованными
значениями: приводит скаляры (`'31'` → `31`, `'true'` → `true`), enum по значению или имени
кейса, вложенные DTO из массивов, union-типы перебором, подставляет дефолты и `null`.
Все ошибки собираются и отдаются одним `BadRequestException` с полем `errors`:
`["email: field is required", "address.zip: must be a string"]`.

## Ответы

`Responder::respond($result, ?int $status)`:

| Возврат хэндлера | Ответ |
|---|---|
| `ResponseInterface` | как есть (статус заменяется, если задан `#[Status]`) |
| `null` | 204 без тела |
| `string` | `text/html; charset=utf-8` |
| массив, `JsonSerializable`, объект | `application/json`, флаги `JSON_UNESCAPED_UNICODE`, `JSON_UNESCAPED_SLASHES`, `JSON_PRESERVE_ZERO_FRACTION` |

## Ошибки: RFC 9457 Problem Details

`ExceptionHandlerMiddleware` стоит первым и превращает любое исключение в
`application/problem+json`:

```json
{"type":"about:blank","title":"Not Found","status":404,"detail":"No route for GET /nope"}
```

- `Keel\Http\Exception\HttpException` и наследники (`BadRequest`, `Unauthorized`,
  `Forbidden`, `NotFound`, `MethodNotAllowed`, `Conflict`, `UnprocessableContent`) задают
  статус, заголовки (например `Allow`, `WWW-Authenticate`) и безопасное для клиента
  сообщение в `detail`; `errors` добавляется, если есть.
- Любое другое исключение — 500 с `title` «Internal Server Error», без `detail`; оно
  логируется через `LoggerInterface` из контейнера, если он зарегистрирован.
- В `debug` добавляется `detail` и объект `exception` с классом, файлом, строкой и трейсом.
- Если клиент предпочитает `text/html` (по `Accept`, и JSON не указан раньше), вместо JSON отдаётся
  HTML-страница: в prod только статус и заголовок, в debug — цепочка исключений, трейс, запрос
  с замаскированными `Authorization`/`Cookie` и кнопка «Copy as Markdown» для баг-репорта.

## Валидация

Атрибуты `Keel\Validation\*` на параметрах конструктора DTO проверяются гидратором после
приведения типов, ошибки попадают в тот же список `errors`:

```php
final readonly class SignUp
{
    public function __construct(
        #[NotBlank, Length(min: 2, max: 50)] public string $name,
        #[Email] public string $email,
        #[Range(min: 18)] public int $age,
        #[Pattern('/^[a-z0-9-]+$/', 'must be a slug')] public string $slug = '',
        #[Choice(['free', 'pro'])] public string $plan = 'free',
        #[Count(max: 5)] public array $tags = [],
        #[Url(schemes: ['https'])] public ?string $site = null,
    ) {}
}
```

Правила: `NotBlank`, `Length`, `Range`, `Count`, `Pattern`, `Email`, `Url`, `Choice`. Правило
применяется только к переданным полям; `null` проверяет лишь `NotBlank`; при ошибке типа правила
поля не запускаются. Своё правило — класс-атрибут, реализующий `Keel\Validation\Rule::check()`.

## Встроенные middleware

Подключаются списком в `HttpConfig`, конфиги — обычные объекты в `config/`:

| Middleware | Конфиг | Что делает |
|---|---|---|
| `CorsMiddleware` | `CorsConfig` | отвечает на preflight (204) без роутера, добавляет `Access-Control-*`; origin точный, `*` или шаблон `https://*.example.com` |
| `RequestIdMiddleware` | — | берёт корректный `X-Request-Id` или генерирует; атрибут `request_id` и заголовок ответа |
| `TrustedProxiesMiddleware` | `TrustedProxiesConfig` | применяет `X-Forwarded-For/Proto/Host/Port` только от доверенных CIDR; IP клиента в атрибуте `client_ip` |
| `MethodOverrideMiddleware` | — | `POST` + `X-HTTP-Method-Override` → `PUT`/`PATCH`/`DELETE` |

## Прочее

- `MethodOverrideMiddleware` — опциональный; подменяет метод только у `POST` и только на
  `PUT`/`PATCH`/`DELETE` по заголовку `X-HTTP-Method-Override`.
- `SapiEmitter` отправляет ответ через SAPI (php-fpm, встроенный сервер); пример
  фронт-контроллера — `examples/hello/public/index.php`.
