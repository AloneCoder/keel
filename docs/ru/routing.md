# Роутинг

Namespace `Keel\Routing`. Маршруты объявляются атрибутами на методах контроллеров,
компилируются в таблицы сопоставления и кэшируются в PHP-файл.

## Объявление

```php
use Keel\Routing\Attribute\{Get, Post, Put, Delete, Route, Prefix, Middleware, Status};

#[Prefix('/api/users')]
#[Middleware(AuthMiddleware::class)]
final class UserController
{
    #[Get('/{id:\d+}', name: 'users.show')]
    public function show(int $id): UserDto { /* ... */ }

    #[Post('')]
    #[Status(201)]
    public function create(#[FromBody] CreateUser $command): UserDto { /* ... */ }

    #[Route('/export', methods: ['GET', 'POST'], name: 'users.export')]
    #[Middleware(RateLimit::class)]
    public function export(): ResponseInterface { /* ... */ }
}
```

- `#[Get]`, `#[Post]`, `#[Put]`, `#[Patch]`, `#[Delete]` — сокращения для `#[Route]` с одним методом.
  Атрибуты повторяемые: один метод может обслуживать несколько путей.
- `#[Prefix]` на классе добавляется ко всем путям класса. Пустой путь `''` в методе даёт
  ровно префикс.
- `#[Middleware]` на классе применяется ко всем маршрутам класса и выполняется раньше
  middleware метода.
- `#[Status]` задаёт статус ответа, который `Responder` построит из возвращённого значения.
- Хэндлер — только публичный нестатический метод. Замыкания не поддерживаются: их нельзя
  скомпилировать в кэш.

## Синтаксис пути

- `{name}` — сегмент без `/` (`[^/]+`).
- `{name:regex}` — своё регулярное выражение, например `{id:\d+}`, `{year:\d{4}}`,
  `{lang:(ru|en)}`. Захватывающие группы внутри превращаются в незахватывающие.
- Путь начинается с `/`. Завершающий слэш — часть пути: `/users` и `/users/` разные маршруты.
- Опциональных сегментов нет: объявите два маршрута на одном методе.

## Сопоставление

Статические пути ищутся в хэше, динамические — одним `preg_match` на чанк из 10 маршрутов
(регулярные выражения объединяются через `(?|...)`, номер совпавшей группы определяет
маршрут, как в FastRoute). Сопоставление идёт по **сырому** пути запроса без декодирования,
чтобы `%2F` не менял структуру; значения плейсхолдеров декодируются через `rawurldecode`
по отдельности. Ограничение `\d+` проверяется до декодирования, поэтому `/users/%34%32`
не проходит.

Результат `Router::match($method, $path)` — `MatchResult` со статусом `Found`
(`RouteMatch` с маршрутом и параметрами), `NotFound` или `MethodNotAllowed` со списком
`allowed`. `HEAD` без своего маршрута обслуживается маршрутом `GET`.

## Кэш

```php
Router::fromControllers(UserController::class)->write('var/cache/routes.php');  // при сборке
$router = Router::fromCache('var/cache/routes.php');                            // в проде
```

Файл кэша — PHP-массив, читается одним `require` и попадает в opcache. В dev
`Router::fromControllers()` на каждый запрос.

## Генерация URL

```php
$router->generate('users.show', ['id' => 5]);                   // /api/users/5
$router->generate('users.show', ['id' => 5, 'page' => 2]);      // /api/users/5?page=2
```

Лишние параметры уходят в query string, `null` пропускается, значения кодируются
`rawurlencode`. Отсутствующий параметр, нарушение паттерна и неизвестное имя дают
`RoutingException`.

## Ошибки конфигурации

`Keel\Routing\Exception\RoutingException` (наследник `LogicException`): путь без ведущего
`/`, повторённое имя плейсхолдера, невалидный паттерн, дважды объявленное имя маршрута,
дважды объявленный статический путь для одного метода, статический или абстрактный хэндлер.
