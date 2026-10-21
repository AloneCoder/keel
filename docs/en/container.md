# Container

A PSR-11 2.0 container with constructor autowiring, attributes, PHP 8.4 lazy objects,
per-request reset and compilation into a PHP class. Namespace `Keel\Container`.

## Two implementations, one behaviour

| | `Container` | compiled class |
|---|---|---|
| When | dev, tests | prod |
| How it is built | `new Container($definitions)` | `new Compiler($definitions)->write($path)`, then `new KeelCompiledContainer($instances)` |
| Dependency resolution | reflection on every first `get()` | generated code; reflection only for classes unknown at compile time |

Both implement `Keel\Container\ContainerInterface` (PSR-11 plus `tagged()` and `reset()`) and pass
the same test suite (`tests/Container/ContainerBehaviourTestCase.php`).

## Registration

```php
use Keel\Container\{Container, Definitions, Ref};

$d = new Definitions();
$d->parameter('db.dsn', 'pgsql://db');                  // scalar, array or enum
$d->instance(HttpConfig::class, $config);                // pre-built object
$d->set(SystemClock::class);                             // reads class attributes
$d->set(Connection::class)
    ->factory(ConnectionFactory::class . '::create')    // static method
    ->lazy();
$d->set('mailer.special', Mailer::class)
    ->argument('logger', new Ref('logger.special'));     // explicit argument
$d->alias(Clock::class, SystemClock::class);
$d->load(new HttpProvider(), new DbProvider());          // ServiceProvider::register(Definitions)

$container = new Container($d);
```

A class that is not registered anywhere is still created on demand when it is instantiable
and every constructor parameter can be resolved (autowiring can be switched off with the
second `Container` argument).

## Resolving constructor and factory arguments

For each parameter, in this order:

1. explicit `->argument('name', value)`; a `Ref` is resolved from the container, anything else is passed as is;
2. `#[Inject('id')]`: the service or parameter with that id;
3. `#[TaggedIterator('tag')]`: a list of every service carrying the tag;
4. a parameter typed with a class or interface: the service with that name; when the parameter
   has a default value the service is injected only if the container knows it (`has()`);
5. the default value;
6. `null` for a nullable type;
7. otherwise `ContainerException` naming the parameter and the class.

## Class attributes

| Attribute | Effect |
|---|---|
| `#[Shared]` | one instance per container (the default) |
| `#[Transient]` | new instance on every `get()`; excluded from `reset()` |
| `#[Lazy]` | `newLazyGhost`: the constructor runs on first property access, not on `get()`; factories use `newLazyProxy` |
| `#[Alias(Iface::class)]` | the id `Iface` resolves to this class (repeatable) |
| `#[Tag('name')]` | included in `tagged('name')` and `#[TaggedIterator('name')]` (repeatable) |

Attributes are read on `Definitions::set()` and when autowiring an unregistered class. Fluent
calls made after `set()` override attributes. An explicit `Definitions::alias()` overrides `#[Alias]`.

About lazy objects: calling a method does not initialize a ghost; reading or writing a property
does. A lazy service breaks dependency cycles: `A(B)`, `#[Lazy] B(A)` works.

## Reset between requests

A service that accumulates request state implements `Keel\Container\Resettable`. In worker mode
the kernel calls `$container->reset()` after every request: only instantiated shared services
are visited, uninitialized lazy objects are skipped.

## Compilation

```php
new Compiler($d)->write('var/cache/container.php', 'KeelCompiledContainer', 'App\Generated');
require 'var/cache/container.php';
$container = new \App\Generated\KeelCompiledContainer([HttpConfig::class => $config]);
```

Every definition and every class reachable from it through constructor types is compiled.
Objects from `Definitions::instance()` never end up in generated code: their ids are listed
in `INSTANCE_IDS` and the compiled class requires them in its constructor.

Not compilable, reported as `ContainerException` at compile time:

- closure factories and `[$object, 'method']` factories: use `'Class::method'` or
  `[Class::class, 'method']` instead (the owner object is taken from the container);
- arguments and parameters holding objects: register them with `instance()`;
- lazy services with a non-public constructor.

The file is written atomically (temporary file + `rename`).

## Errors

- `NotFoundException` (PSR `NotFoundExceptionInterface`): the id is unknown and cannot be autowired;
- `CircularDependencyException`: a cycle without a lazy link; the message lists the whole chain;
- `ContainerException`: everything else; the message includes the resolution chain when there is one.
