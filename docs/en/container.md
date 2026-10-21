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
and every constructor parameter can be resolved. Autowiring can be switched off with the
second constructor argument of either container (`autowire: false`), with the same meaning in
both: only registered definitions resolve, a required dependency on an unregistered class fails
with the resolution chain and an optional one gets its default. The compiled class remembers
which classes it autowired at compile time (`AUTOWIRED`) and sets them aside when the flag is off.

Lookup order of `get()`/`has()`, the same in both containers: pre-built instances, then
definitions (registered or autowired), then parameters.

An id is either an alias or a definition, never both: `alias('x', ...)` after `set('x')`,
`set('x')` after `alias('x', ...)` and a `#[Alias('x')]`/`->alias('x')` colliding with a
definition `x` all throw `ContainerException` instead of silently shadowing one of them.
Register the implementation under its own id and alias the interface to it. The one
exception is `set(Iface::class, Impl::class)` when `Impl` carries `#[Alias(Iface::class)]`:
an alias to its own id is ignored. Alias loops are rejected when the container is built.

Factory methods must be public: a private or protected `Class::method` is refused by both
containers with `Factory Class::method of "id" must be public` (reflection could call it, the
generated code could not).

## Resolving constructor and factory arguments

For each parameter, in this order:

1. explicit `->argument('name', value)`; a `Ref` is resolved from the container, anything else is passed as is;
2. `#[Inject('id')]`: the service or parameter with that id;
3. `#[TaggedIterator('tag')]`: a list of every service carrying the tag;
4. a parameter typed with a class or interface: the service with that name; when the parameter
   has a default value or is nullable (`?Foo $foo`) the service is injected only if the container
   knows it (`has()`), otherwise the default (`null` for the nullable case) is passed;
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
are visited, uninitialized lazy objects are skipped. A `reset()` that throws does not stop the
others: every service is visited, then one `ContainerException` listing the failures (the first
one as `getPrevious()`) is thrown.

## Compilation

```php
new Compiler($d)->write('var/cache/container.php', 'KeelCompiledContainer', 'App\Generated');
require 'var/cache/container.php';
$container = new \App\Generated\KeelCompiledContainer([HttpConfig::class => $config]);
```

Every definition and every class reachable from it through constructor types is compiled.
Objects from `Definitions::instance()` never end up in generated code: their ids are listed
in `INSTANCE_IDS` and the compiled class requires them in its constructor. The class name and
namespace must be valid PHP identifiers; service ids are arbitrary strings and are escaped.

The generated class carries `CONTENT_HASH`, `Compiler::contentHash()` of its own file. PHP
cannot reload a class, so `App` compares the two when the class is already loaded and refuses
a cache rewritten in the same process.

Not compilable, reported as `ContainerException` at compile time:

- closure factories and `[$object, 'method']` factories: use `'Class::method'` or
  `[Class::class, 'method']` instead (the owner object is taken from the container);
- arguments and parameters holding objects: register them with `instance()`;
- lazy services with a non-public constructor.

The file is written atomically (temporary file + `rename`).

## Errors

- `NotFoundException` (PSR `NotFoundExceptionInterface`): the requested id is unknown and cannot be
  autowired. Only the id passed to `get()` can be "not found": a dependency missing deeper in the
  chain is a `ContainerException` whose message carries the resolution chain, since `has()` is
  true for the requested id;
- `CircularDependencyException`: a cycle without a lazy link; the message lists the whole chain;
- `ContainerException`: everything else; the message includes the resolution chain when there is one.

A missing dependency carries its diagnosis as well: `$dependency`, `$reason` (not registered,
autowiring disabled, an interface or abstract class, a non-public constructor), `$autowire` and
`$chain`, and `report()` lays them out for a console, which is what `keel:compile` and
`keel:doctor` print:

```
Cannot resolve service App\Service\OrderService

Dependency: App\Repository\OrderRepository
Reason: autowiring is disabled and the class is not registered
Autowiring: disabled

Resolution path:
App\Controller\OrderController -> App\Service\OrderService -> App\Repository\OrderRepository
```

`new Diagnostics($definitions, $autowire)->check()` runs the same resolution for every registered
service, alias and tag without instantiating anything and returns one such exception per problem;
`keel:doctor` and a test on your `Definitions` can fail before the first request does.
