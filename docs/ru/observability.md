# Здоровье и метрики

Пространства имён `Keel\Health` и `Keel\Metrics`. Оба — глобальные middleware, которые отвечают на
свои пути до роутинга и не требуют контроллера.

Раз они отвечают до роутинга, `/health`, `/metrics` (а также `/openapi.json` и `/docs` из
[OpenAPI](openapi.md)) нельзя закрыть атрибутом `#[Auth]`, и они перекрывают маршрут приложения
с тем же путём. Не выставляйте их в открытый интернет: ограничьте доступ на обратном прокси или
файрволе либо смените пути в конфигах.

## Проверки здоровья

```php
#[Tag(HealthCheck::TAG)]
final class DatabaseCheck implements HealthCheck
{
    public function __construct(private readonly \PDO $pdo) {}

    public function name(): string { return 'database'; }

    public function check(): HealthResult
    {
        $started = hrtime(true);
        try {
            $this->pdo->query('SELECT 1');
        } catch (\PDOException $e) {
            return HealthResult::down($e->getMessage(), exception: $e);
        }
        $ms = (hrtime(true) - $started) / 1e6;

        return $ms > 200 ? HealthResult::degraded('slow', ['ms' => $ms]) : HealthResult::up(data: ['ms' => $ms]);
    }
}
```

Класс с тегом `keel.health` регистрирует discovery, а `HealthMiddleware` (`HttpConfig`) получает его
через инъекцию. Два эндпоинта, пути в `HealthConfig` (`config/health.php`), `null` отключает:

- `/health` (readiness): запускает все проверки, отвечает
  `{"status": "up"|"degraded"|"down", "checks": {...}}` с 200 или 503, если хоть одна проверка
  down. Проверка, бросившая исключение, считается down. `Cache-Control: no-store`.
- `/health/live` (liveness): `{"status": "up"}` без запуска чего-либо, пока воркер отвечает.

Тексты исключений в отчёт не попадают: брошенное исключение или `HealthResult::down()` с
переданным `exception` показывает `"detail": "check failed"`, а само исключение пишется в лог
через `LoggerInterface`, если он зарегистрирован. Ошибки соединения называют хосты,
пользователей и пути, а эндпоинт обычно доступен без учётных данных.
`new HealthConfig(exposeDetails: true)` возвращает тексты — для разработки. Детали, которые вы
пишете сами (`down('queue lag 5s')`), показываются всегда.

Зависимости проверки контейнер подставляет при сборке middleware, поэтому соединение, открытие
которого может не удаться, берите лениво (замыкание или контейнер): так делает `PdoCheck`, и
недоступная база — это запись `down` в отчёте, а не 500 на каждый запрос.

Направляйте сюда пробы Kubernetes, балансировщики и мониторинг доступности; readiness-проверки
держите дешёвыми, они выполняются на каждую пробу.

## Метрики Prometheus

`MetricsMiddleware` (`HttpConfig`) пишет две метрики на каждый запрос и отдаёт текстовый формат на
`/metrics` (`MetricsConfig`, `config/metrics.php`):

```
http_requests_total{method="GET",route="/users/{id}",status="200"} 42
http_request_duration_seconds_bucket{method="GET",route="/users/{id}",le="0.01"} 40
http_request_duration_seconds_count{method="GET",route="/users/{id}"} 42
http_request_duration_seconds_sum{method="GET",route="/users/{id}"} 0.3123
```

Метка `route` — шаблон маршрута, так что кардинальность ограничена; запросы без маршрута помечены
`unmatched`. Метка `method` — одно из `GET HEAD POST PUT PATCH DELETE OPTIONS`
(`MetricsMiddleware::METHODS`); любой другой метод записывается как `OTHER`, чтобы клиент не мог
раздувать набор меток выдуманными глаголами. Поскольку middleware работает до роутинга, шаблон
берётся вторым сопоставлением по скомпилированным таблицам: для статических маршрутов это поиск
по хэшу. Корзины латентности настраиваются (`MetricsConfig::$buckets`, в секундах); в выводе
перечислены все настроенные корзины по возрастанию, с `0` для тех, куда ещё ничего не попало,
затем `+Inf`, `_count` и `_sum`.

### Свои метрики

`Registry` — общий сервис: регистрируйте метрики один раз (в провайдере или в сервисе, который их
пишет) и пишите откуда угодно:

```php
final class OrderService
{
    public function __construct(private readonly Registry $metrics)
    {
        $metrics->counter('orders_total', 'Размещённые заказы', ['channel'])
            ->histogram('order_value_eur', 'Сумма заказа', [], [10, 50, 100, 500]);
    }

    public function place(Order $order): void
    {
        $this->metrics->inc('orders_total', [$order->channel]);
        $this->metrics->observe('order_value_eur', [], $order->total);
    }
}
```

Счётчики (`inc`), gauge (`set`) и гистограммы (`observe`) с метками; `has()` говорит, зарегистрирована
ли метрика, `render()` возвращает текстовый формат.

### Хранилище

Выборки живут в `MetricsStorage`: APCu, если расширение загружено, тогда каждый дочерний процесс
php-fpm или поток FrankenPHP одного сервера прибавляет к одним и тем же числам; иначе память
текущего процесса (картина одного воркера). Внутри значения целые; gauge и суммы гистограмм
масштабированы на 10⁶, чтобы атомарные инкременты APCu годились для длительностей, — это
ограничивает один gauge или сумму примерно ±9,2·10¹² (большие значения обрезаются до предела).
Зарегистрируйте реализацию под `MetricsStorage` для всего остального.

## Идентификаторы запросов и логи

`RequestIdMiddleware` даёт каждому запросу id (заголовок ответа `X-Request-Id`, атрибут запроса
`request_id`); добавляйте его в контекст логов для корреляции. Встроенный `StreamLogger` пишет
JSON-строки в stderr, который собирают FrankenPHP и RoadRunner; см. [Приложение](app.md#логирование).
