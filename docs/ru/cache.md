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
| `FileCache` | всех воркеров хоста | один сериализованный файл на запись, атомарная запись, просроченные удаляются при чтении и вероятностной уборкой (`sweep()` запускает её вручную) |
| `ArrayCache` | только текущего процесса | тесты и мемоизация в пределах воркера |

Ключи по PSR-16: до 256 символов, без `{}()/\@:`; плохой ключ бросает `InvalidKeyException`
(это `Psr\SimpleCache\InvalidArgumentException`). TTL — секунды или `DateInterval`;
неположительный TTL удаляет. Сохранённый `null` считается попаданием.

## Worker mode

`ArrayCache` — кэш на воркер: при четырёх воркерах запись вычислится до четырёх раз, а
`clear()` дойдёт до одного из них. Для всего, что должно быть согласовано между воркерами,
используйте APCu или файлы, а `ArrayCache` — для данных, которые дёшево пересобрать.

У `RateLimit` и `Metrics` свои хранилища с тем же делением APCu/память; через этот кэш они
не ходят.
