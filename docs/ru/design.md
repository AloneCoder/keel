# Keel — проектные решения

Имя: **Keel** (киль — минимальная несущая конструкция). Пакет `keel/framework`,
namespace `Keel\`. Имя утверждено 2026-10-21; vendor `keel` на Packagist и организация `keel`
на GitHub свободны на эту дату.

Документ фиксирует решения с обоснованием (формат ADR в миниатюре). Обоснование опирается
на [analysis.md](analysis.md).

## 1. Цели и не-цели

**Цели**
- Микро-фреймворк для HTTP API и небольших сервисов на PHP ≥ 8.4.
- Worker-first: один и тот же код без правок работает под php-fpm, FrankenPHP worker и
  RoadRunner.
- PSR-стек целиком: 7, 11, 14, 15, 17, 3, 20. Любой PSR-15 middleware и PSR-11 контейнер
  из экосистемы подключается без адаптеров.
- Атрибуты для роутинга, middleware, команд и слушателей; discovery с явно ограниченным
  скоупом и кэшем.
- Компиляция контейнера и роутов в PHP-файлы; в dev — runtime reflection без кэша.
- Ядро небольшое: ориентир 3–4 тысячи строк и не более пяти runtime-зависимостей. На prod-старте
  загружается только то, что нужно для ответа; всё для сборки (discovery, определения, компилятор,
  рефлексия) остаётся в `keel:compile`.
- Производительность на уровне Symfony MicroKernel в FrankenPHP worker (ориентир из
  issue Tempest #2309: порядка 25k rps на простом JSON-эндпоинте на одной машине).

**Не-цели (первая версия)**
- ORM, шаблонизатор, auth, сессии, очереди, async-рантайм на Fibers, ACL. Это отдельные
  пакеты поверх ядра, если понадобятся.
- Поддержка Swoole/OpenSwoole как целевого рантайма. Возможна через адаптер позже.
- Обратная совместимость с PHP < 8.4.

## 2. Ключевые решения

### D1. Минимум PHP 8.4, фичи 8.5 по feature-detection
8.4 — 32 % установок, 8.5 — 16 %. Lazy objects и property hooks есть в 8.4. `ext/uri`,
`clone with`, замыкания в атрибутах используем только при `PHP_VERSION_ID >= 80500`,
с fallback. Пересмотр минимума на 8.5 — конец 2027.

### D2. Берём готовым: PSR-7/17, логирование, консоль
- `nyholm/psr7` + `nyholm/psr7-server` — единственная runtime-зависимость кроме `psr/*`.
  Заменяется любой PSR-17 фабрикой через контейнер.
- `monolog/monolog` и `symfony/console` — `suggest`, не `require`. Ядро работает без них.
- Свои реализации PSR-7, логгера, консоли не пишем.

### D3. Пишем сами: контейнер, роутер, пайплайн, ядро, discovery, конфиг, события
Причины по каждому компоненту — в разделе 3. Общий аргумент: готовые варианты либо не
компилируют (php-di для autowired-классов), либо тяжёлые (symfony/*), либо устарели
(FastRoute 1.x без типов, 2.0 не вышла).

### D4. Без глобального состояния
Ни одного `static` хранилища состояния, ни синглтонов, ни глобальных функций-хелперов типа
`app()`. Всё, что живёт дольше запроса, лежит в контейнере и реализует `Resettable`, если
накапливает состояние. Запрос передаётся явно как `ServerRequestInterface`. Это прямое
следствие worker mode и урока CVE-2026-24894.

### D5. Один shared-скоуп в контейнере, без request-скоупа
Request-scoped сервисы — источник утечек в worker mode. Вместо них: всё, что зависит от
запроса, получает `ServerRequestInterface` аргументом хэндлера или читает атрибуты запроса.
Сервисы с per-request кэшем реализуют `Resettable::reset()`, ядро вызывает его в
`terminate()`.

### D6. Атрибуты + discovery с явным скоупом
Роуты, middleware, команды, слушатели объявляются атрибутами. Discovery сканирует только
каталоги из `DiscoveryConfig::$paths` (по умолчанию `src/`), никогда `vendor/`. Результат
кэшируется в `var/cache/discovery.php`; в dev инвалидация по mtime каталогов. Пакеты
могут регистрировать свои классы явно через провайдер, без сканирования.

### D7. Компиляция в PHP-файлы
`keel compile` (или первый запрос в prod) генерирует `var/cache/container.php`
(класс с методами-фабриками, без reflection в рантайме) и `var/cache/routes.php`
(статический массив + сгруппированные regex по методу, подход FastRoute GroupCountBased).
В dev оба строятся на лету.

### D8. Типизированный конфиг
Файлы `config/*.php` возвращают объекты (`final readonly class HttpConfig {...}`), а не
массивы. Контейнер регистрирует их по классу. Переменные окружения читаются один раз при
старте в `Env`; сервисы не трогают `$_ENV`/`getenv()` напрямую. YAML/XML не поддерживаем.

### D9. Рантаймы как адаптеры
`RuntimeInterface::run(Kernel): int`. Реализации: `FpmRuntime`, `FrankenPhpRuntime`
(цикл `frankenphp_handle_request`, `MAX_REQUESTS`, `gc_collect_cycles()` после запроса,
`\Throwable` ловится внутри цикла), `RoadRunnerRuntime` (через `spiral/roadrunner-http`,
опциональная зависимость), `ConsoleRuntime`. Автовыбор: `KEEL_RUNTIME`, иначе по
`function_exists('frankenphp_handle_request')` и `RR_MODE`.

### D10. Ошибки — RFC 9457 Problem Details
Единый `ExceptionHandlerMiddleware`: в prod — JSON `application/problem+json`, в dev —
HTML со стеком и кнопкой «скопировать как Markdown». Исключения `HttpException` несут
статус и заголовки.

### D11. Монорепо, один пакет на старте
`keel/framework` с `src/<Component>/`. Компоненты не зависят друг от друга циклически, чтобы
позже разрезать на `keel/container`, `keel/router` и т.д. через subtree split.

### D12. Качество как часть ядра
PHPStan level max, PER-CS 3.x (php-cs-fixer), PHPUnit 12, Infection позже. Отдельный
security-набор тестов: method override, плейсхолдеры роутов с constraint-обходом, утечки
состояния между запросами в worker-цикле. Бенчмарк-харнесс против Slim 4 и Symfony
MicroKernel на FrankenPHP в `benchmarks/`.

## 3. Компоненты

```
src/
  Container/   PSR-11 2.0, autowiring, атрибуты, lazy ghosts, компилятор
  Http/        PSR-15 пайплайн, резолвер аргументов хэндлера, ответы, HttpException
  Routing/     атрибуты Route/Get/Post/..., Prefix, Middleware; компилятор и матчер; UrlGenerator
  Kernel/      Kernel (boot/handle/terminate), ExceptionHandlerMiddleware, Problem Details
  Runtime/     RuntimeInterface, Fpm, FrankenPhp, RoadRunner, Console
  Discovery/   сканер классов по каталогам, кэш, DiscoveryInterface для расширений
  Config/      Env, загрузчик config/*.php, базовые конфиг-объекты
  Event/       PSR-14 диспетчер, атрибут Listener
  Console/     интеграция с symfony/console, атрибут Command (опционально)
```

### Container
- `ContainerInterface` (PSR-11 2.0) + `Definitions` для провайдеров:
  `$d->set(Foo::class)->factory(fn(Bar $b) => new Foo($b))->shared()`.
- Autowiring по типам конструктора; скаляры — через `#[Inject('param.name')]` или значение
  из конфиг-объекта.
- Атрибуты: `#[Shared]` (по умолчанию), `#[Transient]`, `#[Lazy]` (→ `newLazyGhost`),
  `#[Alias(Interface::class)]`, `#[Tag('name')]` + `#[TaggedIterator('name')]`.
- `Resettable` интерфейс; `Container::reset()` обходит инициализированные shared-сервисы.
- Компилятор: генерирует `final class CompiledContainer` с методом на сервис; lazy через
  `ReflectionClass::newLazyGhost` в сгенерированном коде.
- Ошибки: `NotFoundException`, `ContainerException` с цепочкой зависимостей в сообщении.

### Routing
- `#[Route('/users/{id:\d+}', methods: ['GET'], name: 'users.show')]` и шорткаты
  `#[Get]`, `#[Post]`, `#[Put]`, `#[Patch]`, `#[Delete]`.
- `#[Prefix('/api/v1')]` и `#[Middleware(Auth::class)]` на классе и методе.
- Хэндлер: метод контроллера (`[Class, method]`), invokable класс, closure (только dev/тесты,
  не компилируется).
- Матчер: статические маршруты через хэш, динамические через сгруппированные regex по
  HTTP-методу. 405 с `Allow`, 404. HEAD → GET автоматически. Метод override только из
  `X-HTTP-Method-Override` при явном включении в конфиге (урок Flight CVE-2026-42551).
- `UrlGenerator::generate('users.show', ['id' => 5])`.

### Http
- `Pipeline` — PSR-15 `RequestHandlerInterface`, middleware FIFO в порядке объявления.
- `ArgumentResolver`: параметры хэндлера заполняются из route params (с приведением к
  int/float/bool/enum), `ServerRequestInterface`, сервисов контейнера, `#[FromQuery]`,
  `#[FromBody]` (JSON → readonly DTO через простой маппер).
- Возврат: `ResponseInterface` как есть; `array`/`JsonSerializable` → JSON 200;
  `string` → text/html; `null` → 204. `#[Status(201)]` на методе.
- `HttpException` и наследники (`NotFound`, `MethodNotAllowed`, `BadRequest`, …).

### Kernel
- `Kernel::boot(string $projectDir, Env $env): self` — конфиг, контейнер, роуты.
- `handle(ServerRequestInterface): ResponseInterface` — пайплайн: глобальные middleware →
  `RoutingMiddleware` → middleware маршрута → хэндлер.
- `terminate(ServerRequestInterface, ResponseInterface): void` — события `Terminate`,
  `Container::reset()`.
- Глобальные middleware и провайдеры — в `config/app.php` явным списком.

### Discovery
- Сканирует `.php` в указанных каталогах, определяет FQCN по namespace/class токенам (без
  `include`), создаёт `ReflectionClass` только для файлов с атрибутами из интересующего
  списка (быстрая проверка по тексту `#[`).
- Результат — `DiscoveryResult` (роуты, middleware, команды, слушатели, провайдеры).
- Расширение: `DiscoveryInterface { public function discover(\ReflectionClass $c): void; }`.

### Runtime
- `FrankenPhpRuntime`: `while (frankenphp_handle_request($handler)) { $kernel->terminate(); gc_collect_cycles(); if (++$n >= $max) break; }`.
- `FpmRuntime`: один запрос, `ServerRequestCreator` из `nyholm/psr7-server`, эмиттер с
  поддержкой `headers_send(103)` при наличии.

## 4. Пример использования

```php
// public/index.php
return static fn (array $context): Kernel => Kernel::boot(dirname(__DIR__), Env::fromArray($context));
```

```php
// src/Http/UserController.php
#[Prefix('/api/users')]
#[Middleware(Auth::class)]
final class UserController
{
    public function __construct(private readonly UserRepository $users) {}

    #[Get('/{id:\d+}', name: 'users.show')]
    public function show(int $id): UserDto
    {
        return $this->users->find($id) ?? throw new NotFoundException();
    }

    #[Post('')]
    #[Status(201)]
    public function create(#[FromBody] CreateUser $cmd): UserDto
    {
        return $this->users->create($cmd);
    }
}
```

## 5. Дорожная карта

| Этап | Содержимое | Критерий готовности |
|---|---|---|
| M0 | репозиторий, composer, CI (phpstan, cs, phpunit), документы | `composer test` зелёный на пустом ядре |
| M1 | Container + компилятор | 100 % покрытие контейнера, бенчмарк компилированного vs reflection |
| M2 | Http + Routing + Kernel | hello-world и CRUD-пример под php-fpm, security-тесты роутера |
| M3 | Runtime: FrankenPHP worker, RoadRunner | пример в Docker, тест утечек между запросами |
| M4 | Discovery, Config, Event, Console | кэш discovery, `keel compile`, `keel routes` |
| M5 | Документация, бенчмарки против Slim 4 и Symfony MicroKernel, релиз 0.1.0 | README с цифрами |

## 6. Принятые организационные решения (2026-10-21)

1. Имя `Keel`, vendor `keel`, namespace `Keel\`.
2. Хостинг — GitHub, лицензия MIT, публичный репозиторий.
3. Документация на двух языках: `docs/ru/` и `docs/en/`, README на английском с
   русской версией `README.ru.md`. Оба языка поддерживаются в актуальном состоянии в одном
   изменении.
4. Роутер пишем свой с самого начала (раздел 3, Routing); `league/route` как fallback не
   рассматривается.
