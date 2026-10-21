# Аутентификация и ограничение частоты

Пространства имён `Keel\Auth` и `Keel\RateLimit`. Оба работают через атрибуты маршрута, которые
сами себя применяют: `#[Auth]` добавляет к маршруту свой guard-middleware, `#[RateLimit]` тоже,
так что маршрут не может быть защищён на бумаге и открыт в рантайме. Генератор OpenAPI читает те же
атрибуты.

## Principal

Тот, от чьего имени аутентифицирован запрос, реализует `Principal` (`id()`, `roles()`,
`hasRole()`). `Identity` — простая реализация: id, роли и произвольные атрибуты. Хэндлеры получают
principal по типу, как и сессию:

```php
#[Get('/me')]
#[Auth]
public function me(Principal $user): array { return ['id' => $user->id()]; }

#[Get('/feed')]
public function feed(?Principal $user = null): array { /* персонализация, если вошёл */ }
```

## Аутентификаторы

`Authenticator` распознаёт одну схему учётных данных: возвращает `null`, если в запросе нет
учётных данных его вида, `Principal`, если они верны, и бросает `UnauthorizedException`, если они
есть, но неверны (плохой токен никогда не считается анонимом). Встроенные:

| Аутентификатор | Читает | Требует |
|---|---|---|
| `BearerTokenAuthenticator` | `Authorization: Bearer <token>` | `PrincipalResolver` |
| `ApiKeyAuthenticator` | `X-Api-Key: <key>` (заголовок настраивается) | `PrincipalResolver` |
| `HmacAuthenticator` | `Authorization: HMAC <keyId>:<timestamp>:<signature>` | `HmacSecrets` |

`PrincipalResolver::resolve(string $credential): ?Principal` и
`HmacSecrets::secretFor(string $keyId): ?string` реализуете вы (база, кэш, статическая карта);
регистрируйте их под интерфейсом через `#[Alias]` или в провайдере:

```php
#[Alias(PrincipalResolver::class)]
final class ApiTokens implements PrincipalResolver
{
    public function __construct(private readonly TokenRepository $tokens) {}

    public function resolve(string $credential): ?Principal
    {
        $row = $this->tokens->find(hash('sha256', $credential));

        return $row === null || $row->expired() ? null : new Identity($row->userId, $row->roles);
    }
}
```

`config/auth.php` перечисляет аутентификаторы по порядку:

```php
return new AuthConfig([BearerTokenAuthenticator::class, HmacAuthenticator::class]);
```

Каждый — сервис контейнера, конструктор автовайрится; чтобы поменять realm, имя заголовка или
допуск HMAC, опишите сервис с аргументами в провайдере.

### Подпись запросов HMAC

Для машинных клиентов, которым не стоит держать bearer-токен. Клиент подписывает строку
`"METHOD\n/path?query\n<unix timestamp>\n" . sha256(body)` алгоритмом HMAC-SHA256 и шлёт
`Authorization: HMAC <keyId>:<timestamp>:<hex-подпись>`. Сервер принимает метки времени в пределах
`$tolerance` секунд (по умолчанию 300), что ограничивает повтор; повтор с другим телом, путём или
методом не пройдёт, потому что они входят в подписываемую строку. Заголовок собирает
`HmacAuthenticator::sign($keyId, $secret, $method, $target, $body, time())`; principal в
результате — `Identity` с id, равным key id. `sign()` отвергает key id, который не поместится в
заголовок (пустой, длиннее 128 байт, с двоеточием или пробелом).

*Идентичный* запрос, повторённый в пределах допуска, принимается, если у аутентификатора нет
`CounterStorage` (четвёртый аргумент конструктора; автовайрится, как только хранилище
зарегистрировано — то есть сразу после настройки ограничения частоты): тогда каждая подпись
принимается один раз, а повтор получает 401 «Replayed signature». Клиент, повторяющий запрос
после потерянного ответа, должен подписать его заново со свежей меткой времени.

Неверная подпись и неизвестный key id получают один и тот же ответ «Invalid signature» после
одинакового объёма работы, так что перебрать key id нельзя. Host и `Content-Type` в
подписываемую строку не входят: подпись действительна на любом хосте с тем же секретом, поэтому
никогда не используйте один HMAC-секрет в разных окружениях.

## Требование аутентификации

`#[Auth]` на методе или классе (все маршруты контроллера) требует principal; с ролями — любого,
у кого есть хотя бы одна:

```php
#[Delete('/users/{id}')]
#[Auth('admin', 'support')]
public function delete(int $id): void { /* ... */ }
```

Атрибут добавляет к маршруту `AuthGuard`. Guard берёт principal, который оставил на запросе
`AuthMiddleware`, либо аутентифицирует сам, если того middleware нет в конвейере; без principal
отвечает 401 с заголовком `WWW-Authenticate`, перечисляющим challenge каждой схемы, с principal
без нужных ролей — 403. Оба ответа — Problem Details.

`AuthMiddleware` — необязательный глобальный middleware (`HttpConfig`): аутентифицирует каждый
запрос с учётными данными и прикрепляет principal, так что публичные маршруты могут
персонализировать ответ, а неверные учётные данные отклоняются везде, не только на защищённых
маршрутах.

`Authentication` — сервис за обоими: `authenticate($request)` прогоняет настроенные
аутентификаторы, `principal($request)` читает атрибут, `unauthorized()` строит 401.

## Ограничение частоты

`#[RateLimit(limit, window, by)]` разрешает `limit` запросов за `window` секунд (по умолчанию 60)
на IP клиента (`by: 'ip'`, по умолчанию) или на аутентифицированного principal
(`by: 'principal'`, для анонимных запросов — на IP):

```php
#[Post('/search')]
#[RateLimit(30, 60)]
public function search(#[FromBody] Query $query): array { /* ... */ }
```

Атрибут добавляет `RateLimitGuard`: каждый ответ несёт `RateLimit-Limit`, `RateLimit-Remaining`
и `RateLimit-Reset` (секунд до конца окна; имена заголовков — из раннего черновика IETF, а не
новое единое структурированное поле `RateLimit`); по исчерпании guard отвечает 429 с
`Retry-After`. Окна фиксированные и выровнены по часам (`RateLimiter`), поэтому на границе окна
может пройти до двух лимитов подряд; IP клиента — тот, что установил `TrustedProxiesMiddleware`,
так что заголовки forwarded учитываются только от доверенных прокси.

Ключ счётчика — HTTP-метод, шаблон маршрута и субъект (`GET|/search|ip:203.0.113.5`,
`GET|/mine|principal:u1`): два метода на одном пути и IP, похожий на id principal, никогда не
делят один счётчик. При `by: 'principal'` guard сам аутентифицирует запрос, если principal ещё не
прикреплён (нет `AuthMiddleware` или `#[Auth]` объявлен после `#[RateLimit]`): запрос с учётными
данными всегда считается по principal, неверные данные получают 401, и только по-настоящему
анонимный запрос считается по IP.

Счётчики живут в `CounterStorage`: APCu, если расширение загружено (общий для всех воркеров
сервера), иначе память текущего процесса. Зарегистрируйте свою реализацию под `CounterStorage`
для Redis или базы; `InMemoryCounters` подходит для тестов. `ApcuCounters` при сбое закрывается:
если APCu не может сохранить счётчик (общая память исчерпана), лимит считается превышенным, а не
тихо отключается — подбирайте `apc.shm_size` под количество ключей.

## Тестирование

`TestClient` шлёт заголовки как любой клиент: `$client->get('/me', ['Authorization' => 'Bearer t'])`
или `$client->withHeader('X-Api-Key', 'k')`. Для HMAC используйте `HmacAuthenticator::sign()`.
В тестах с лимитами регистрируйте `InMemoryCounters` как `CounterStorage`.
