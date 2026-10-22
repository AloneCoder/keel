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

## Повторы и circuit breaker

Два декоратора PSR-18 включаются из конфига, провайдер ставит их поверх `CurlClient` (повторы
снаружи, breaker внутри, так что каждая попытка проходит через breaker). Собранные вручную, они
оборачивают любой PSR-18 клиент, в том числе Guzzle.

```php
// config/http_client.php
return new HttpClientConfig(
    timeout: 5.0,
    retry: new RetryConfig(
        maxAttempts: 3,       // включая первую попытку
        delay: 0.1,           // перед первым повтором, дальше x multiplier
        maxDelay: 2.0,        // потолок одного ожидания, в том числе Retry-After
        multiplier: 2.0,
        jitter: true,         // ждать от половины до всей задержки
        statuses: [408, 429, 500, 502, 503, 504],
    ),
    circuitBreaker: new CircuitBreakerConfig(
        failures: 5,          // сбоев подряд, после которых цепь хоста размыкается
        openFor: 30,          // секунд отказа, затем одна пробная попытка
        window: 60,           // сбои забываются через столько секунд после последнего
        statuses: [500, 502, 503, 504],
        shared: true,         // состояние в APCu: воркеры сервера размыкают цепь вместе
    ),
);
```

`RetryingClient` повторяет запрос после сетевого сбоя или ответа из `statuses`:

- Только запросы, которые безопасно повторить: `GET`, `HEAD`, `OPTIONS`, `PUT`, `DELETE`,
  `TRACE` или любой метод с заголовком `Idempotency-Key` (на стороне сервера — `#[Idempotent]`).
  Тело, которое нельзя перемотать, дважды не отправляется; перематываемое перематывается перед
  каждой попыткой.
- Ждёт `delay × multiplier^(n-1)`, не больше `maxDelay`, с джиттером. `Retry-After` (секунды
  или HTTP-дата) заменяет backoff; `Retry-After` больше `maxDelay` сразу возвращает ответ:
  ожидание держало бы воркер, который обслуживает клиента.
- Результат — ответ или исключение последней попытки. `RequestException` (неверен сам запрос) и
  `CircuitOpenException` не повторяются никогда. Каждый повтор пишется в лог уровнем `info`
  без query string.

Повторы выполняются внутри запроса, которому нужен ответ: при `timeout: 5.0` и трёх попытках
клиент может ждать больше 15 с. На пути запроса держите `timeout`, `maxAttempts` и `maxDelay`
маленькими.

`CircuitBreakerClient` держит по цепи на origin (схема, хост, порт):

- **Closed** (замкнута): вызовы идут; сетевые сбои и `statuses` считаются, любой другой ответ
  обнуляет счёт. 429 по умолчанию не сбой: хост жив и просит притормозить.
- **Open** (разомкнута) после `failures` сбоев подряд: вызовы сразу падают с
  `CircuitOpenException` (PSR-18 `NetworkExceptionInterface`, с запросом и `$circuit`), не трогая
  сеть. Размыкание пишется в лог как warning.
- **Half-open** через `openFor` секунд: первый вызов — пробный, остальным отказывают ещё
  `openFor`; успех пробы замыкает цепь, сбой снова размыкает.

С `shared: true` и загруженным APCu состояние общее для всех дочерних процессов php-fpm или
потоков FrankenPHP сервера, так что цепь размыкается после `failures` вызовов в сумме, а не в
каждом воркере; одновременные сбои считаются приблизительно. Без APCu у каждого воркера свои
цепи. `CircuitBreaker` работает и отдельно, для вызовов не по HTTP:

```php
$breaker = new CircuitBreaker(new CircuitBreakerConfig(failures: 3), $cache);   // любое хранилище PSR-16
if (!$breaker->allow('search')) {
    return $this->fallback();
}
try {
    $result = $this->search->query($q);
    $breaker->success('search');
} catch (\Throwable $e) {
    $breaker->failure('search');
    throw $e;
}
$breaker->state('search');   // CircuitState::Closed, Open или HalfOpen
```

## Worker mode

Клиент не хранит состояние запроса; неудачный запрос ничего не оставляет. Переиспользование
соединений внутри воркера — главная причина держать клиент общим сервисом, а не создавать
его на каждый запрос.
