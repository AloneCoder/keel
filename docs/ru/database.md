# База данных

Пространство имён `Keel\Database`. В Keel нет ORM и построителя запросов: он подключает в
контейнер то соединение, которое вы и так знаете, держит его всю жизнь воркера и следит за ним
через `/health`. На выбор: чистый PDO или Doctrine DBAL.

## PDO

```php
// config/database.php
return static fn(Env $env): PdoConfig => new PdoConfig(
    $env->get('DATABASE_DSN') ?? 'mysql:host=127.0.0.1;dbname=app;charset=utf8mb4',
    $env->get('DATABASE_USER'),
    $env->get('DATABASE_PASSWORD'),
);

// config/services.php
return new ServicesConfig([PdoProvider::class]);
```

`PdoProvider` регистрирует `\PDO` как общий сервис, создаваемый при первом обращении, с
исключениями (`ERRMODE_EXCEPTION`), ассоциативными выборками, нативными prepare и без
приведения к строкам, если `PdoConfig::$options` не говорит иного. Он же регистрирует
`PdoCheck` с тегом `keel.health`: `SELECT 1`, латентность в отчёте и статус degraded, если
осталась открытая транзакция. Соединение проверка получает лениво (замыкание, берущее `\PDO` из
контейнера), поэтому недоступная база — это запись `down` в `/health`, а не исключение при
сборке middleware; на следующей пробе соединение пробуется снова и после успеха сохраняется.
Свои проверки стройте так же: `new PdoCheck(fn() => $container->get(\PDO::class), 'replica')`.
Текст ошибки пишется в лог, а не в отчёт, пока не включён `HealthConfig::$exposeDetails`
(см. [Здоровье и метрики](observability.md#проверки-здоровья)).

```php
final class UserRepository
{
    public function __construct(private readonly \PDO $pdo) {}
}
```

## Doctrine DBAL

```bash
composer require doctrine/dbal
```

```php
// config/database.php
return static fn(Env $env): DbalConfig => DbalConfig::fromUrl($env->get('DATABASE_URL') ?? 'sqlite:///:memory:');

// config/services.php
return new ServicesConfig([DbalProvider::class]);
```

`DbalConfig::fromUrl()` понимает `mysql://`, `mariadb://`, `pgsql://`, `sqlite:///path`,
`sqlite:///:memory:`, `sqlsrv://` и `oci://`; `new DbalConfig([...])` принимает массив
параметров `DriverManager::getConnection()` как есть. `DbalProvider` регистрирует
`Doctrine\DBAL\Connection` (DBAL подключается лениво при первом запросе) и `DbalCheck` с тегом
`keel.health`: dummy select платформы, латентность и статус degraded, если осталась открытая
транзакция.

## Worker mode

Соединение переживает запрос. Отсюда два правила:

- **Никогда не оставляйте транзакцию открытой.** Оборачивайте транзакционную работу в
  `try/finally` или `Connection::transactional()`; `DbalCheck` сообщает об открытой транзакции
  как degraded, потому что следующий запрос на этом воркере выполнится внутри неё.
- **Ожидайте, что сервер закроет простаивающее соединение.** DBAL переподключается после
  `close()`; с PDO ловите исключение «server has gone away» в собственном повторе или
  опустите `MAX_REQUESTS` воркера ниже idle-таймаута сервера, делённого на частоту запросов.
  Общий `\PDO` фреймворк не пересоздаёт: когда сервер его закрыл, `PdoCheck` сообщает о воркере
  как down до его перезапуска — именно этого и ждут от readiness-пробы.

Состояние запроса (identity map, кэш текущего пользователя) живёт в `Resettable`-сервисах,
а не на соединении.

## Миграции и ORM

Doctrine Migrations, Phinx и Doctrine ORM работают поверх соединения DBAL: зарегистрируйте их
сервисы в провайдере, который берёт `Connection` из контейнера. Entity manager ORM — состояние
запроса в worker mode; сделайте сервис `Resettable` и вызывайте `clear()` в `reset()`.
