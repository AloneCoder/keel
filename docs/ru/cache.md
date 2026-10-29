# Кэш

Пространство имён `Keel\Cache`. PSR-16 кэш без зависимостей с тремя драйверами, подключённый
как `Psr\SimpleCache\CacheInterface`; symfony/cache или любая другая реализация PSR-16
подменяется алиасом интерфейса.

```php
// config/cache.php
return static fn(Env $env, string $projectDir): CacheConfig => new CacheConfig(
    driver: 'auto',                           // apcu, если загружен, иначе file; либо "apcu", "file", "array"
    directory: $projectDir . '/var/cache/data',
    prefix: 'myapp:',
    defaultTtl: 3600,                         // секунды для set() без TTL; null = навсегда
);

// config/services.php
return new ServicesConfig([CacheProvider::class]);
```

```php
final class RatesService
{
    public function __construct(private readonly CacheInterface $cache, private readonly ClientInterface $http) {}

    public function rate(string $currency): float
    {
        $rate = $this->cache->get('rate.' . $currency);
        if (!is_float($rate)) {
            $rate = $this->fetch($currency);
            $this->cache->set('rate.' . $currency, $rate, 600);
        }

        return $rate;
    }
}
```

## Драйверы

| Драйвер | Общий для | Примечания |
|---|---|---|
| `ApcuCache` | всех процессов php-fpm или потоков FrankenPHP сервера | самый быстрый; очищается при рестарте; нужен ext-apcu с `apc.enable_cli=1` для консольных команд |
| `FileCache` | всех воркеров хоста | один сериализованный файл на запись, атомарная запись, просроченные удаляются при чтении и вероятностной уборкой (`sweep()` запускает её вручную и заодно убирает временные файлы, оставшиеся от упавшей записи) |
| `ArrayCache` | только текущего процесса | тесты и мемоизация в пределах воркера |
| `RedisCache` (`driver: 'redis'`) | всех воркеров на всех хостах | ext-redis через `RedisProvider` (ниже); значения сериализуются `serialize()`, так что держите инстанс приватным |
| `MemcachedCache` (`driver: 'memcached'`) | всех воркеров на всех хостах | ext-memcached, `servers: ['host:11211']`; ключи длиннее 250 байт хэшируются; `clear()` сбрасывает весь инстанс |

Ключи по PSR-16: до 256 символов, без `{}()/\@:`; плохой ключ бросает `InvalidKeyException`
(это `Psr\SimpleCache\InvalidArgumentException`). TTL — секунды или `DateInterval`;
неположительный TTL удаляет. Сохранённый `null` считается попаданием.

**Каталог `FileCache`.** Записи десериализуются с разрешением любых классов, поэтому тот, кто
может писать в каталог, может подсунуть объекты в ваш процесс. Драйвер создаёт отсутствующий
каталог с правами `0700` и отказывается (`RuntimeException`) от каталога, который является
символической ссылкой, доступен на запись всем или доступен на запись группе, но принадлежит
другому пользователю. Без `directory` по умолчанию берётся предсказуемый путь в системном
временном каталоге: это нормально на хосте, где работает только ваше приложение, а на общей
машине задайте `directory` — приватный путь вроде `$projectDir . '/var/cache/data'`.

`Keel\Cache\Cache` (алиас PSR-интерфейса) добавляет `remember($key, $ttl, fn() => ...)`:
значение из кэша или результат замыкания, сохранённый под ключом. Сохранённый `null` — попадание.

## Redis

`RedisConfig` в `config/redis.php` плюс `RedisProvider` дают контейнеру соединение `\Redis`
(ext-redis, подключается при первом обращении, постоянное между запросами воркера), проверку
здоровья `redis` для `/health` и, по `use`, хранилища Keel, которые иначе живут в APCu или
памяти процесса:

```php
// config/redis.php
return static fn(Env $env): RedisConfig => RedisConfig::fromUrl(
    $env->require('REDIS_URL'),               // redis://:password@host:6379/2, rediss:// для TLS
    prefix: 'myapp:',
    use: ['sessions', 'counters', 'metrics'],
);
// или new RedisConfig(host: '127.0.0.1', port: 6379, auth: null, database: 0, prefix: 'myapp:', use: [...])

// config/cache.php
return new CacheConfig(driver: 'redis', prefix: 'myapp:');
```

| `use` | Регистрирует | Заменяет |
|---|---|---|
| `sessions` | `Keel\Session\RedisStorage` как `SessionStorageInterface` | файловые или cookie-сессии: общие для всех узлов, истекают средствами Redis, продлеваются как файловые |
| `counters` | `Keel\RateLimit\RedisCounters` как `CounterStorage` | счётчики лимитов в APCu: одна корзина на весь парк; при недоступности Redis закрывается (fail closed) |
| `metrics` | `Keel\Metrics\RedisStorage` как `MetricsStorage` | метрики в APCu: один hash на парк, так что `/metrics` на любом узле отдаёт всё |

Каждый ключ начинается с префикса (`myapp:cache:`, `myapp:sess:`, `myapp:rl:`, `myapp:metrics`).
`RedisProvider::connect()` создаёт соединение; зарегистрируйте `RedisConfig` до загрузки
провайдера (конфиги регистрируются первыми, так что `config/redis.php` достаточно).

## Кэш вывода

`#[OutputCache]` отдаёт ответ маршрута целиком из кэша: хэндлер выполняется один раз, следующие
GET и HEAD получают сохранённую копию, пока она не истечёт или пока не сбросят один из её тегов.
Нужен PSR-16 `CacheInterface` (`CacheProvider`).

```php
#[Get('/products/{id}')]
#[OutputCache(ttl: 300, tags: ['products', 'product:{id}'], varyByQuery: ['lang'], varyByHeaders: ['Accept-Language'])]
public function show(int $id): ProductView { /* ... */ }

#[Patch('/products/{id}')]
public function update(int $id, #[FromBody] ProductPatch $patch, OutputCacheStore $pages): ProductView
{
    $product = $this->products->update($id, $patch);
    $pages->invalidateTags('products', "product:$id");

    return ProductView::from($product);
}
```

Ключ — метод, хост и путь, плюс query (`varyByQuery: true` по умолчанию; `false` его игнорирует;
список оставляет только эти параметры), перечисленные заголовки запроса и, с
`varyByPrincipal: true`, principal. Теги могут ссылаться на плейсхолдеры маршрута
(`product:{id}`). `invalidateTags()` выдаёт каждому тегу новую версию; сохранённый ответ помнит
версии, с которыми он записан, и устаревает, как только одна из них сменилась, — это работает на
любом PSR-16 хранилище без перебора ключей. Версии тегов хранятся без срока, а вытесненная версия
читается как неизвестная, так что её ответы становятся устаревшими, а не снова свежими.

Ответы получают `X-Cache: HIT` или `MISS` (`OutputCacheConfig::$header`, null — без заголовка) и
при попадании — `Age`. Сохраняются только ответы 200; никогда — те, что ставят cookie, говорят
`Cache-Control: no-store` или `private`, стримят тело или больше
`OutputCacheConfig::$maxBodyBytes` (1 МиБ), а также ответ, при отрисовке которого хэндлер
записал что-то в сессию. Сохранённую копию делят только анонимные запросы: запрос с учётными
данными (заголовок `Authorization` или `Cookie`, как в конфигурации Varnish по умолчанию, или
principal от любого аутентификатора, включая `X-Api-Key`) идёт мимо кэша, кроме случая, когда
маршрут варьируется по principal и principal к этому моменту уже известен: объявляйте `#[Auth]`
раньше `#[OutputCache]`. Маршрут, у которого `#[Auth]`, `#[Can]` или `#[Signed]` стоят после
`#[OutputCache]` (или висят на методе, а кэш на классе), из кэша не отдаётся никогда: попадание в
кэш пропустило бы проверку.
В отличие от `#[CacheControl]`, который говорит клиентам и прокси, что хранить, этот кэш экономит
работу на сервере.

## Worker mode

`ArrayCache` — кэш на воркер: при четырёх воркерах запись вычислится до четырёх раз, а
`clear()` дойдёт до одного из них. Для всего, что должно быть согласовано между воркерами,
используйте APCu или файлы, а `ArrayCache` — для данных, которые дёшево пересобрать.

У `RateLimit` и `Metrics` свои хранилища с тем же делением APCu/память; через этот кэш они
не ходят.
