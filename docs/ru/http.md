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
$kernel->terminate($request, $response);  // после отправки: ResponseSent, отложенные задачи, Container::reset()
```

`Kernel::build()` регистрирует в контейнере `Psr17Factory` под всеми PSR-17 интерфейсами,
`Responder`, `ArgumentResolver` и `Router`, если приложение не сделало этого само. Для
скомпилированного контейнера и кэша маршрутов используйте конструктор
`new Kernel($container, $router, $middleware, $debug)`; `Kernel::registerDefaults()` нужно
вызвать перед компиляцией. `terminate()` рассылает `ResponseSent`, выполняет `DeferredTasks` и
сбрасывает сервисы; см. [Рантаймы](runtime.md#события-жизненного-цикла).

`Pipeline` — PSR-15 обработчик: middleware выполняются в порядке объявления (FIFO), классы
резолвятся из контейнера на каждом запросе, так что временем их жизни управляет контейнер:
shared-сервис — один экземпляр на весь воркер (реализуйте `Resettable`, чтобы сбрасывать
состояние запроса), transient — новый экземпляр на каждый запрос. Пару `[класс, аргументы]`
(её записывает `#[MiddlewareWith]`, она же допустима в `HttpConfig`) конвейер собирает один раз:
именованные аргументы в конструктор, остальное автовайрится. Экземпляр конвейера не хранит
состояние запроса и переиспользуется между запросами.

## Аргументы хэндлера

`ArgumentResolver` заполняет параметры метода контроллера, для каждого параметра в этом порядке:

1. тип `ServerRequestInterface`, `RouteMatch`, `Route` или `Input` — сам запрос, результат
   роутинга или [читатель запроса](#input);
2. `#[FromQuery]` — DTO гидратируется из всех query-параметров; скаляр читается из параметра
   с именем аргумента (отсутствие без дефолта — 400);
3. `#[FromBody]` — JSON (`application/json`, `*+json`) или `getParsedBody()`; DTO
   гидратируется, `array` получает тело как есть; битый JSON — 400. На скалярном параметре
   (`string`, `int`, ...) атрибут — ошибка конфигурации: `LogicException` на первом запросе;
   `#[FromHeader('X-Request-Id')]` и `#[FromCookie]` (cookie с именем параметра или
   `#[FromCookie('theme')]`) заполняют скаляр так же: приведение к типу (400 при
   несоответствии), дефолт или `null` при отсутствии, иначе 400; `#[FromFile]` привязывает
   загрузку, см. [Файловое хранилище](storage.md#загрузки);
4. плейсхолдер маршрута с именем параметра, приведённый к типу (`int`, `float`, `bool`,
   `string`, enum); несоответствие — 400;
5. сервис из контейнера по типу (`has()`);
6. значение по умолчанию или `null` для nullable;
7. иначе `LogicException` (это ошибка конфигурации, ответ 500).

## Input

`Keel\Http\Input`, внедряемый по типу, — удобное чтение PSR-7-запроса для одиночных параметров
и для middleware; DTO с `#[FromQuery]` и `#[FromBody]` остаются типизированным входом.

```php
#[Post('/search')]
public function search(Input $input): array
{
    $q = $input->string('q', '');             // query и тело слиты, тело побеждает
    $page = $input->int('page', 1);           // приведение как в гидраторе; "abc" — 400
    $city = $input->get('address.city');      // пути через точку во вложенные данные
    $tags = $input->array('tags', []);
    $token = $input->bearerToken();
    $ip = $input->ip();                       // из TrustedProxiesMiddleware, иначе REMOTE_ADDR
    // ...
}
```

`all()`, `get($key, $default)`, `has()`, `filled()` (не null, "" и []), `only()`, `except()`,
`query()`, `string()`/`int()`/`float()`/`bool()`/`array()` с дефолтом для отсутствующего ключа,
`param($name)` (плейсхолдер маршрута), `header()`, `cookie()`, `file()`/`files()` (загрузки),
`bearerToken()`, `ip()`, `method()`, `path()`, `isSecure()`, `isJson()` (JSON-тело),
`wantsJson()` (JSON первым в `Accept` или JSON-тело без `Accept`), `isAjax()` и `request()` для
всего остального. Тело читается при первом обращении и только для методов кроме GET и HEAD;
битый JSON — 400.

## Гидратор

`Hydrator::hydrate(Dto::class, array $data)` вызывает конструктор DTO с именованными
значениями: приводит скаляры (`'31'` → `31`, `'true'` → `true`), enum по значению или имени
кейса, вложенные DTO из массивов, union-типы перебором, подставляет дефолты и `null`.
Все ошибки собираются и отдаются одним `BadRequestException` с полем `errors`:
`["email: field is required", "address.zip: must be a string"]`.

Конструктор DTO может отвергнуть сочетание значений, которое не выразить одним правилом, бросив
`InvalidArgumentException` или `DomainException`: сообщение станет ещё одной ошибкой, для
вложенного DTO — с префиксом пути поля (`range: end must not be before start`). Любое другое
исключение из конструктора — ошибка в самом DTO, и ответ будет 500.

JSON-тело декодируется с лимитом вложенности PHP по умолчанию — 512 уровней; документ глубже —
это 400 («Maximum stack depth exceeded»). *Размер* тела фреймворк не ограничивает: задайте
`post_max_size`, `client_max_body_size` или аналог вашего сервера перед ним.

Целые должны помещаться в `int`: `"99999999999999999999"` или `1e20` — это «must be an
integer», а не молча обрезанное значение. Члены union перебираются в фиксированном порядке:
`int`, `float`, `bool`, `string`, затем классы и enum — независимо от порядка объявления (сам PHP
отражает `int|string` как `string|int`): для `int|string` значение `"42"` станет `42`, а `"x"`
останется строкой.

## Пагинация

`#[FromQuery] PageRequest $page` читает `?page=3&perPage=50` (по умолчанию 1 и 20, `perPage` не
больше 100; выход за границы — 400, как у любого query-DTO); `offset()` и `limit()` идут в запрос
к хранилищу. Верните `Page`, и ответом будет конверт
`{"items": [...], "page": 3, "perPage": 50, "total": 93, "pages": 2}`:

```php
/** @return Page<UserView> */
#[Get('/users')]
public function list(#[FromQuery] PageRequest $page): Page
{
    return Page::of($this->users->slice($page->offset(), $page->limit()), $page, $this->users->count());
}
```

`@return Page<UserView>` (или `#[Response(200, type: Page::class, items: UserView::class)]`) даёт
документу OpenAPI тип элементов. Чтобы изменить умолчания или верхнюю границу, унаследуйте
`PageRequest` со своим конструктором.

## Ответы

`Responder::respond($result, ?int $status)`:

| Возврат хэндлера | Ответ |
|---|---|
| `ResponseInterface` | как есть (статус заменяется, если задан `#[Status]`) |
| `null` | 204 без тела |
| `string` | `text/html; charset=utf-8` |
| `Keel\Http\Redirect` | 3xx с `Location`: `Redirect::to('/login')`, `Redirect::route('users.show', ['id' => 5], 303)`, см. [Роутинг](routing.md#редиректы) |
| `Keel\Http\EventStream` | `text/event-stream`, формируется по ходу отправки, см. [Стриминг](#стриминг-и-server-sent-events) |
| массив, `JsonSerializable`, объект | `application/json`, флаги `JSON_UNESCAPED_UNICODE`, `JSON_UNESCAPED_SLASHES`, `JSON_PRESERVE_ZERO_FRACTION` |

## Стриминг и Server-Sent Events

```php
#[Get('/orders/{id}/progress')]
public function progress(int $id): EventStream
{
    return new EventStream(function () use ($id) {
        foreach ($this->tracker->follow($id) as $step) {
            yield new SseEvent($step, event: 'progress', id: (string) $step->sequence);
        }
        yield new SseEvent('done', event: 'end');
    });
}

#[Get('/orders/export')]
public function export(Responder $responder): ResponseInterface
{
    return $responder->stream(function () {
        foreach ($this->orders->cursor() as $order) {
            yield json_encode($order) . "\n";
        }
    }, 'application/x-ndjson');
}
```

Тело ответа — `IteratorStream`: ничего не выполняется, пока эмиттер его не читает, и каждый
кусок уходит клиенту сразу, как только генератор его выдал. Элементы `EventStream` — объекты
`SseEvent` (`data`, `event`, `id`, `retry`, `comment`), строки (уходят как `data`) или любое
значение, которое кодируется в JSON; `SseEvent::comment('ping')` — обычный heartbeat, чтобы
прокси не закрывали простаивающее соединение. Поток событий отдаётся с `Cache-Control: no-cache`
и `X-Accel-Buffering: no`, чтобы nginx пропускал события, а не буферизовал их.

- `SapiEmitter` сбрасывает буферы вывода PHP после каждого куска, а когда `connection_aborted()`
  сообщает, что клиента нет, останавливается и закрывает тело — генератор завершается (его
  `finally` выполняется). Держите `zlib.output_compression` выключенным и проверьте, что прокси
  не буферизует.
- `RoadRunnerRuntime` отправляет неперематываемое тело как поток RoadRunner, по кадру на кусок
  (RoadRunner 2023.3 или новее); любое другое тело по-прежнему уходит одним кадром.
- Строка статуса и заголовки отправлены до запуска генератора: брошенное им исключение рантайм
  логирует как critical, а ответ обрывается. Ловите что можно внутри.
- Открытый поток занимает воркер (дочерний процесс php-fpm, поток FrankenPHP, воркер RoadRunner)
  до своего конца. Рассчитывайте пул на ожидаемое число потоков и оставляйте запас для обычных
  запросов через `LoadSheddingMiddleware` (`maxInFlight`, см. [Рантаймы](runtime.md#сброс-нагрузки)).
- `HttpCacheMiddleware`, `#[OutputCache]` и ключи идемпотентности потоковые тела не трогают.

## Ошибки: RFC 9457 Problem Details

`ExceptionHandlerMiddleware` стоит первым и превращает любое исключение в
`application/problem+json`:

```json
{"type":"about:blank","title":"Not Found","status":404,"detail":"No route for GET /nope"}
```

- `Keel\Http\Exception\HttpException` и наследники (`BadRequest`, `Unauthorized`,
  `Forbidden`, `NotFound`, `MethodNotAllowed`, `Conflict`, `ContentTooLarge`,
  `UnsupportedMediaType`, `UnprocessableContent`, `TooManyRequests`, `ServiceUnavailable`) задают
  статус, заголовки (например `Allow`, `WWW-Authenticate`) и безопасное для клиента
  сообщение в `detail`; `errors` добавляется, если есть.
- Любое другое исключение — 500 с `title` «Internal Server Error», без `detail`; оно
  логируется через `LoggerInterface` из контейнера, если он зарегистрирован.
- В `debug` добавляется `detail` и объект `exception` с классом, файлом, строкой и трейсом.
- Если клиент по `Accept` предпочитает `text/html` JSON-у (выше `q`, а при равном `q` — указан
  раньше), вместо JSON отдаётся HTML-страница: в prod только статус и заголовок, в debug —
  цепочка исключений, трейс, запрос с замаскированными `Authorization`/`Cookie` и кнопка
  «Copy as Markdown» для баг-репорта.
- Тело кодируется с `ProblemDetails::JSON_FLAGS` (`Responder::JSON_FLAGS` плюс
  `JSON_INVALID_UTF8_SUBSTITUTE`), поэтому сообщение с битыми байтами не ломает ответ об
  ошибке. Если рендер всё же упал (например, `HttpException` с заголовком, который PSR-7 не
  принимает), сбой логируется как `critical`, а клиент получает минимальный problem `{"status":500}`.

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

Правила: `NotBlank`, `Length`, `Range`, `Count`, `Pattern`, `Alnum(allow: '-_')`, `Email`, `Url`,
`Uuid`, `Ip(version: 4|6)`, `Hostname`, `Date(format: 'Y-m-d')`, `Json`, `Timezone`, `Choice`,
`Unique` (без повторяющихся элементов) и `Each(new NotBlank(), new Length(max: 30))`, которое
прогоняет свои правила по каждому элементу массива и сообщает о первом неподходящем как
`tags: [2]: must not be blank`. Правило применяется только к переданным полям; `null` проверяет лишь `NotBlank`; при ошибке типа правила
поля не запускаются. Своё правило — класс-атрибут, реализующий `Keel\Validation\Rule::check()`.

## Встроенные middleware

Подключаются списком в `HttpConfig`, конфиги — обычные объекты в `config/`:

| Middleware | Конфиг | Что делает |
|---|---|---|
| `CorsMiddleware` | `CorsConfig` | отвечает на preflight (204) без роутера, добавляет `Access-Control-*`; origin точный, `*` или шаблон `https://*.example.com`; `allowCredentials` требует явного списка origin (`*` отклоняется в конструкторе) |
| `RequestIdMiddleware` | — | берёт корректный `X-Request-Id` или генерирует; атрибут `request_id` и заголовок ответа |
| `TrustedProxiesMiddleware` | `TrustedProxiesConfig` | применяет `X-Forwarded-For/Proto/Host/Port` только от доверенных CIDR; IP клиента в атрибуте `client_ip` |
| `HttpCacheMiddleware` | `HttpCacheConfig` | `Cache-Control` и `ETag` для ответов 200 на GET/HEAD, 304 по `If-None-Match` / `If-Modified-Since`; см. ниже |
| `CsrfMiddleware` | `CsrfConfig` | synchronizer-токен поверх сессии, см. [Сессии](session.md#защита-от-csrf) |
| `SecurityHeadersMiddleware` | `SecurityHeadersConfig` | HSTS (только HTTPS), CSP, `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, COOP и CORP; см. ниже |
| `TrustedHostsMiddleware` | `TrustedHostsConfig` | 400 для хоста вне белого списка; см. ниже |
| `BodyLimitMiddleware` | `BodyLimitConfig` | 413 сверх лимита размера, 415 для неразрешённого media type; `#[BodyLimit]` на маршрут |
| `ServerTimingMiddleware` | `ServerTimingConfig` | `Server-Timing` с общим временем и замерами хэндлеров, по желанию `X-Response-Time` |
| `IdempotencyMiddleware` | `IdempotencyConfig` | сохранённый ответ на повтор с тем же `Idempotency-Key`; `#[Idempotent]` на маршрут |
| `LoadSheddingMiddleware` | `LoadSheddingConfig` | 503 с `Retry-After` при перегрузке, см. [Рантаймы](runtime.md#сброс-нагрузки) |
| `MethodOverrideMiddleware` | — | `POST` + `X-HTTP-Method-Override` → `PUT`/`PATCH`/`DELETE` |
| `AuthMiddleware` | `AuthConfig` | аутентифицирует запросы с учётными данными, см. [Аутентификация](auth.md) |
| `HealthMiddleware` | `HealthConfig` | `/health` и `/health/live`, см. [Здоровье и метрики](observability.md) |
| `MetricsMiddleware` | `MetricsConfig` | метрики Prometheus и `/metrics` |
| `OpenApiMiddleware` | `OpenApiConfig` | `/openapi.json` и `/docs`, см. [OpenAPI](openapi.md) |

Атрибуты маршрута добавляют свои middleware: `#[Deprecated]` — `DeprecationMiddleware` (ниже),
`#[OutputCache]` — `OutputCacheMiddleware` (см. [Кэш](cache.md#кэш-вывода)).

`TrustedProxiesMiddleware` берёт самый правый адрес `X-Forwarded-For`, который сам не является
доверенным прокси. Если доверены все (как при `proxies: ['*']`), берётся самый правый валидный
адрес — его дописал ближайший к нам прокси; самый левый, который прислал клиент, не берётся
никогда. Доверенный `X-Forwarded-Proto`, меняющий схему, заодно сбрасывает порт слушающего
сокета, если `X-Forwarded-Port` или порт в `X-Forwarded-Host` не задают другой.

Порядок в списке важен. Рабочий вариант для API за прокси:

```php
return new HttpConfig([
    TrustedProxiesMiddleware::class,    // схема, хост и IP клиента такими, какими их видел клиент
    HealthMiddleware::class,            // пробы: любой Host, никогда не сбрасываются
    MetricsMiddleware::class,
    LoadSheddingMiddleware::class,
    TrustedHostsMiddleware::class,
    ServerTimingMiddleware::class,      // меряет всё, что ниже
    OpenApiMiddleware::class,           // до строгого CSP: его HTML-страница грузит скрипт
    SecurityHeadersMiddleware::class,
    CorsMiddleware::class,
    BodyLimitMiddleware::class,
    RequestIdMiddleware::class,
    AuthMiddleware::class,
    IdempotencyMiddleware::class,       // после аутентификации: ключи привязаны к principal
]);
```

### HTTP-кэширование

`HttpCacheMiddleware` работает с ответами 200 на GET и HEAD. Он добавляет настроенный
`Cache-Control`, если хэндлер его не задал, `ETag` — хэш тела, если хэндлер не задал свой
(`etag: false` отключает), и отвечает пустым 304 с сохранением валидаторов и кэш-заголовков на
запрос, чей `If-None-Match` совпал с ETag (слабое сравнение, списки и `*` понимаются) или чей
`If-Modified-Since` не старше `Last-Modified` ответа. Хэндлер при этом выполняется: экономится
передача, а не работа.

```php
// config/http_cache.php: умолчания для глобального middleware
return new HttpCacheConfig(etag: true);

// на маршрут или контроллер
#[Get('/catalog')]
#[CacheControl(maxAge: 300, public: true)]            // public, max-age=300 + ETag
public function catalog(): array { /* ... */ }

#[Get('/me')]
#[CacheControl(noStore: true)]                        // no-store, без ETag
public function me(Principal $user): array { /* ... */ }
```

`#[CacheControl(maxAge, sharedMaxAge, public, mustRevalidate, immutable, noStore, etag)]`
добавляет middleware к маршруту со своими настройками: работает и без глобального middleware,
а при обоих сразу перекрывает `HttpCacheConfig`. Глобальный middleware выполняется до роутинга
и видит только конфиг; настройки на маршрут даёт именно атрибут. Хэширование читает всё тело,
поэтому для больших выгрузок и потоков отключайте `etag` (неперематываемое тело пропускается
и так).

### Заголовки безопасности

```php
// config/security_headers.php
return SecurityHeadersConfig::api();   // только JSON: CSP "default-src 'none'; frame-ancestors 'none'"

return new SecurityHeadersConfig(
    contentSecurityPolicy: "default-src 'self'; img-src 'self' data:",
    permissionsPolicy: ['camera' => [], 'geolocation' => ['self']],   // camera=(), geolocation=(self)
);
```

По умолчанию: `Strict-Transport-Security: max-age=31536000; includeSubDomains` только на
HTTPS-запросах (по обычному HTTP браузеры его игнорируют; за прокси, снимающим TLS, схему даёт
`TrustedProxiesMiddleware`), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
`Referrer-Policy: strict-origin-when-cross-origin` и `Cross-Origin-Opener-Policy: same-origin`.
CSP (можно в режиме report-only), `Permissions-Policy` и `Cross-Origin-Resource-Policy` выключены,
пока не заданы; `hstsPreload` включайте только после подачи домена в preload-список. Заголовок,
который в ответе уже есть, не трогается, так что отдельная страница (HTML-отчёт, справочник API)
может отдать свою политику. `X-Powered-By` добавляет сам PHP: выставьте `expose_php = Off`.

### Доверенные хосты

```php
// config/trusted_hosts.php
return new TrustedHostsConfig(['api.example.com', '*.example.com']);
```

Запрос к любому другому хосту получает 400 ещё до роутинга, поэтому подделанный `Host` не
попадает в генерируемые URL, редиректы и ссылки сброса пароля. `*.example.com` покрывает
поддомены любой глубины, но не сам `example.com`; IP-литералы (`10.0.0.5`, `[::1]`) и `*`
допустимы. Хост берётся из URI запроса — после того как `TrustedProxiesMiddleware` применил
доверенный `X-Forwarded-Host`. Пробам, которые обращаются к поду по IP, нужен этот IP в списке
или `HealthMiddleware` раньше в `HttpConfig`.

### Лимиты тела запроса

```php
// config/body_limit.php: каждый запрос с телом
return new BodyLimitConfig(maxBytes: '1M', contentTypes: ['application/json', 'application/*+json']);

#[Post('/avatars')]
#[BodyLimit('20M', contentTypes: ['multipart/form-data', 'image/*'])]
public function upload(#[FromFile] UploadedFileInterface $avatar): array { /* ... */ }
```

`Content-Length` сверх лимита получает 413 до чтения тела; тело без него (chunked) оборачивается
так, что чтение за лимит бросает 413, а не забивает память воркера. Тело, чей media type не
подходит ни под один шаблон (точный, `type/*` или `type/*+suffix`), получает 415. Запросы без
тела проходят как есть. Глобальный middleware ещё до роутинга дешёвым матчингом читает
`#[BodyLimit]` маршрута, поэтому маршрут может поднять лимит выше глобального; аргумент `null`
оставляет глобальное значение. Атрибут работает и без глобального middleware, а документ OpenAPI
перечисляет для маршрута 413 и 415. Собственные лимиты сервера срабатывают раньше:
`post_max_size` в php-fpm, `http.max_request_size` в RoadRunner, `request_body` в Caddy.

### Ключи идемпотентности

```php
// config/idempotency.php; нужен PSR-16 кэш (CacheProvider)
return new IdempotencyConfig(ttl: 86_400);

#[Post('/payments')]
#[Idempotent(required: true)]
public function pay(#[FromBody] Payment $payment): array { /* ... */ }
```

Клиент отправляет небезопасный запрос с `Idempotency-Key: <uuid>`
(draft-ietf-httpapi-idempotency-key-header). Первый запрос выполняется, и его ответ (любой статус
ниже 500, кроме 429) сохраняется на `ttl` секунд. Повтор с тем же ключом и теми же методом,
путём, query и телом получает этот ответ с пометкой `Idempotent-Replayed: true` без запуска
хэндлера; тот же ключ с другим запросом получает 422; повтор, пока первый ещё выполняется, —
409 с `Retry-After`. Запрос, который упал (5xx или исключение), освобождает ключ, и повтор
выполняется заново. Ключ — 1-255 видимых ASCII-символов (строка structured field в кавычках тоже
принимается); ключи привязаны к principal, а без него — к заголовку `Authorization`: ставьте
middleware после `AuthMiddleware`.

Блокировка — атомарный инкремент `CounterStorage`: APCu, Redis (`RedisConfig(use: ['counters'])`)
или, без них, память процесса, которая защищает только один воркер. Глобальный middleware
покрывает POST и PATCH (`IdempotencyConfig::$methods`) с ключом и учитывает `#[Idempotent]`
маршрута; атрибут сам по себе действует на маршрут с любым методом и документирует в OpenAPI
заголовок, 409 и 422. Потоковые ответы не сохраняются.

### Server-Timing

```php
// config/server_timing.php
return new ServerTimingConfig(responseTimeHeader: 'X-Response-Time');

#[Get('/users/{id}')]
public function show(int $id, ServerTiming $timing): UserView
{
    $user = $timing->measure('db', fn() => $this->users->find($id), 'User lookup');
    $timing->add('cache', 0.4);

    return UserView::from($user);
}
```

`ServerTimingMiddleware` меряет всё, что ниже него, и отвечает
`Server-Timing: db;dur=3.2;desc="User lookup", cache;dur=0.4, total;dur=7.9` — браузеры показывают
это в панели сети. `ServerTiming` — свежий сборщик на каждый запрос, хранится атрибутом запроса и
внедряется по типу (`ServerTiming::of($request)` в middleware); `start()`/`stop()`, `measure()` и
`add()` записывают миллисекунды. `timingAllowOrigin` разрешает страницам других origin читать
цифры, `metrics: false` оставляет только общее время. Тайминги рассказывают любому, сколько
отвечают ваши бэкенды: подумайте, прежде чем открывать их в публичном API.

### Устаревшие маршруты

```php
#[Get('/v1/users')]
#[Deprecated(since: '2026-07-01', sunset: '2027-01-01', link: 'https://docs.example.com/v2', successor: 'https://api.example.com/v2/users')]
public function list(): array { /* ... */ }
```

Ответы маршрута несут `Deprecation: @1782864000` (RFC 9745), `Sunset: Fri, 01 Jan 2027 00:00:00
GMT` (RFC 8594), `Link: <...>; rel="deprecation"` и `Link: <...>; rel="successor-version"`; без
`since` маршрут объявляется устаревшим уже сейчас (`@0`). Операция OpenAPI получает
`deprecated: true` и `x-sunset`, а `keel:routes` помечает хэндлер. Даты читаются в UTC; sunset
раньше `since` или URL, который не помещается в заголовок `Link`, — ошибка при компиляции
маршрутов. Ошибки, брошенные хэндлером, отдаются без этих заголовков. Не путать с атрибутом
самого PHP `#[\Deprecated]`, из-за которого вызов метода порождает `E_USER_DEPRECATED`.

## Прочее

- `MethodOverrideMiddleware` — опциональный; подменяет метод только у `POST` и только на
  `PUT`/`PATCH`/`DELETE` по заголовку `X-HTTP-Method-Override`.
- `SapiEmitter` отправляет ответ через SAPI (php-fpm, встроенный сервер); пример
  фронт-контроллера — `examples/hello/public/index.php`. Неперематываемое тело стримится, см.
  выше. `SapiEmitter::finish()` завершает ответ для клиента, а скрипт продолжает работу
  (`fastcgi_finish_request()`, `frankenphp_finish_request()`); `FpmRuntime` вызывает его перед
  `Kernel::terminate()`.
