# HTTP-клиент

Пространство имён `Keel\HttpClient`. PSR-18 клиент на ext-curl, подключённый как
`Psr\Http\Client\ClientInterface`; запросы строятся фабриками PSR-17, которые ядро и так
регистрирует. Guzzle или symfony/http-client подставляются алиасом интерфейса.

```php
// config/http_client.php
return new HttpClientConfig(
    timeout: 10.0,            // весь запрос, секунды
    connectTimeout: 3.0,
    maxRedirects: 5,          // 0 отключает переход по редиректам
    verifyTls: true,
    userAgent: 'myapp/1.0',
    headers: ['Accept' => 'application/json'],   // если запрос не задал заголовок сам
    proxy: null,              // "http://proxy:3128"
);

// config/services.php
return new ServicesConfig([HttpClientProvider::class]);
```

```php
final class GeoClient
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
    ) {}

    public function lookup(string $ip): array
    {
        $request = $this->requests->createRequest('GET', 'https://geo.example.com/' . rawurlencode($ip))
            ->withHeader('Authorization', 'Bearer ' . $this->token);
        $response = $this->http->sendRequest($request);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('geo lookup failed: ' . $response->getStatusCode());
        }

        return json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
```

## Поведение

- Коды статуса возвращаются, а не бросаются, как требует PSR-18. `NetworkException` (DNS,
  соединение, TLS, таймаут; `getCode()` — номер ошибки curl) и `RequestException`
  (неподдерживаемая схема) реализуют интерфейсы исключений PSR-18.
- Редиректы проходятся до `maxRedirects` самим клиентом, а не curl, поэтому ведут себя как в
  браузере: 301, 302 и 303 превращают запрос с телом в `GET` без тела, 307 и 308 повторяют
  запрос как есть, `Authorization`, `Proxy-Authorization` и `Cookie` снимаются, если цель —
  другой origin (схема, хост или порт), относительный `Location` разрешается относительно URI
  запроса, и переход выполняется только на `http`/`https`. Ответ — от последнего перехода;
  по достижении лимита последний ответ-редирект возвращается как есть.
- Имена заголовков ответа сравниваются без учёта регистра (`Set-Cookie` и `set-cookie` — один
  заголовок со всеми значениями), свёрнутые строки-продолжения склеиваются, а ответ, который
  отвергает фабрика PSR-7, — это `RequestException`.
- Тело `POST`, `PUT`, `PATCH` и `DELETE` отправляется как есть; `GET` и `HEAD` тело не шлют.
  Ответы буферизуются в памяти: для больших загрузок используйте свой вызов curl или
  отдельный клиент.
- Один curl-handle на экземпляр клиента переиспользуется между запросами воркера, так что
  keep-alive соединения с одним хостом переживают запросы. После ошибки handle пересоздаётся.

## Worker mode

Клиент не хранит состояние запроса; неудачный запрос ничего не оставляет. Переиспользование
соединений внутри воркера — главная причина держать клиент общим сервисом, а не создавать
его на каждый запрос.
