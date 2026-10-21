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
- Редиректы проходятся до `maxRedirects`; ответ — от последнего перехода.
- Тело `POST`, `PUT`, `PATCH` и `DELETE` отправляется как есть; `GET` и `HEAD` тело не шлют.
  Ответы буферизуются в памяти: для больших загрузок используйте свой вызов curl или
  отдельный клиент.
- Один curl-handle на экземпляр клиента переиспользуется между запросами воркера, так что
  keep-alive соединения с одним хостом переживают запросы. После ошибки handle пересоздаётся.

## Worker mode

Клиент не хранит состояние запроса; неудачный запрос ничего не оставляет. Переиспользование
соединений внутри воркера — главная причина держать клиент общим сервисом, а не создавать
его на каждый запрос.
