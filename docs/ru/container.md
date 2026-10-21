# Контейнер

PSR-11 2.0 контейнер с autowiring по конструктору, атрибутами, lazy-объектами PHP 8.4,
сбросом состояния между запросами и компиляцией в PHP-класс. Namespace `Keel\Container`.

## Две реализации, одно поведение

| | `Container` | скомпилированный класс |
|---|---|---|
| Когда | dev, тесты | prod |
| Как создаётся | `new Container($definitions)` | `new Compiler($definitions)->write($path)`, затем `new KeelCompiledContainer($instances)` |
| Разрешение зависимостей | рефлексия на каждый первый `get()` | сгенерированный код, рефлексия только для классов, не известных при компиляции |

Оба реализуют `Keel\Container\ContainerInterface` (PSR-11 плюс `tagged()` и `reset()`) и проходят
один и тот же набор тестов (`tests/Container/ContainerBehaviourTestCase.php`).

## Регистрация

```php
use Keel\Container\{Container, Definitions, Ref};

$d = new Definitions();
$d->parameter('db.dsn', 'pgsql://db');                  // скаляр, массив или enum
$d->instance(HttpConfig::class, $config);                // готовый объект
$d->set(SystemClock::class);                             // читает атрибуты класса
$d->set(Connection::class)
    ->factory(ConnectionFactory::class . '::create')    // статический метод
    ->lazy();
$d->set('mailer.special', Mailer::class)
    ->argument('logger', new Ref('logger.special'));     // явный аргумент
$d->alias(Clock::class, SystemClock::class);
$d->load(new HttpProvider(), new DbProvider());          // ServiceProvider::register(Definitions)

$container = new Container($d);
```

Класс, который нигде не зарегистрирован, всё равно создаётся по запросу, если он
инстанцируемый и все параметры конструктора разрешимы (autowiring можно выключить вторым
аргументом `Container`).

## Разрешение аргументов конструктора и фабрики

Для каждого параметра в этом порядке:

1. явный `->argument('name', value)`; `Ref` разрешается из контейнера, остальное подставляется как есть;
2. `#[Inject('id')]` — сервис или параметр по id;
3. `#[TaggedIterator('tag')]` — список всех сервисов с тегом;
4. типизированный классом/интерфейсом параметр — сервис по имени типа; если у параметра есть
   значение по умолчанию, сервис подставляется только когда контейнер его знает (`has()`);
5. значение по умолчанию;
6. `null` для nullable-типа;
7. иначе `ContainerException` с именем параметра и класса.

## Атрибуты класса

| Атрибут | Эффект |
|---|---|
| `#[Shared]` | один экземпляр на контейнер (по умолчанию) |
| `#[Transient]` | новый экземпляр на каждый `get()`; не участвует в `reset()` |
| `#[Lazy]` | `newLazyGhost`: конструктор выполняется при первом обращении к свойству, не при `get()`; для фабрик — `newLazyProxy` |
| `#[Alias(Iface::class)]` | id `Iface` ведёт на этот класс (повторяемый) |
| `#[Tag('name')]` | участвует в `tagged('name')` и `#[TaggedIterator('name')]` (повторяемый) |

Атрибуты читаются при `Definitions::set()` и при autowiring незарегистрированного класса.
Fluent-вызовы после `set()` имеют приоритет над атрибутами. Явный `Definitions::alias()`
имеет приоритет над `#[Alias]`.

Важно про lazy: вызов метода не инициализирует ghost, инициализацию запускает чтение или
запись свойства. Lazy-сервис разрывает циклические зависимости: `A(B)`, `#[Lazy] B(A)` работает.

## Сброс между запросами

Сервис, накапливающий состояние запроса, реализует `Keel\Container\Resettable`. В worker-режиме
ядро вызывает `$container->reset()` после каждого запроса: обходятся только созданные
shared-сервисы, неинициализированные lazy-объекты пропускаются.

## Компиляция

```php
new Compiler($d)->write('var/cache/container.php', 'KeelCompiledContainer', 'App\Generated');
require 'var/cache/container.php';
$container = new \App\Generated\KeelCompiledContainer([HttpConfig::class => $config]);
```

Компилируются все определения и все классы, достижимые из них через типы конструкторов.
Объекты из `Definitions::instance()` в код не попадают: их id перечисляются в
`INSTANCE_IDS`, и конструктор скомпилированного класса требует их на входе.

Не компилируются и дают `ContainerException` на этапе компиляции:

- фабрики-замыкания и фабрики `[$object, 'method']` — замените на `'Class::method'` или
  `[Class::class, 'method']` (экземпляр метода берётся из контейнера);
- аргументы и параметры с объектами — регистрируйте их через `instance()`;
- lazy-сервисы с непубличным конструктором.

Запись файла атомарная (временный файл + `rename`).

## Ошибки

- `NotFoundException` (PSR `NotFoundExceptionInterface`) — id неизвестен и не автовайрится;
- `CircularDependencyException` — цикл без lazy-звена, в сообщении вся цепочка;
- `ContainerException` — всё остальное; сообщение содержит цепочку разрешения, когда она есть.
