# Стабильность API

С 1.0.0 Keel следует семантическому версионированию. Что покрывает обещание:

**Стабильно: всё публичное без пометки `@internal`.** Классы, методы, атрибуты, конфиг-объекты,
исключения и форматы ниже. Ломающее изменение означает новую мажорную версию, по возможности
с deprecation в предыдущей минорной.

| Область | Стабильные точки входа |
|---|---|
| Container | `Definitions`, `Definition`, `Container`, `Compiler`, `ContainerInterface`, `Resettable`, `ServiceProvider`, `Ref`, классы `Attribute\*` и `Exception\*` |
| Routing | `Router`, `Route`, `RouteMatch`, `MatchResult`, `MatchStatus`, классы `Attribute\*`, `Exception\RoutingException` |
| HTTP | `Pipeline`, `CallableHandler`, `Responder`, `ArgumentResolver`, `Hydrator`, `EmitterInterface`, `SapiEmitter`, классы `Attribute\*`, `Exception\*` и `Middleware\*` |
| Validation | `Rule` и все атрибуты-правила |
| Kernel | `Kernel`, `ExceptionHandlerMiddleware`, `ProblemDetails` |
| Runtime | `RuntimeInterface`, `Runtime`, `FpmRuntime`, `FrankenPhpRuntime`, `RoadRunnerRuntime` |
| App | `App` (кроме членов с `@internal`), `Config\*`, `Discovery\DiscoveryInterface`, `Discoverer` и встроенные discovery, `Event\*`, `Log\*`, `Session\*`, `Testing\*`, `Console\ConsoleFactory` |
| Auth (1.1) | `Principal`, `Identity`, `Authenticator`, `PrincipalResolver`, `HmacSecrets`, `SecurityScheme`, встроенные аутентификаторы, `Authentication`, `AuthMiddleware`, `AuthGuard`, `AuthConfig`, `Attribute\Auth` |
| RateLimit (1.1) | `Attribute\RateLimit`, `RateLimiter`, `RateLimitResult`, `RateLimitGuard`, `CounterStorage` и его реализации |
| Health, Metrics (1.1) | `HealthCheck`, `HealthResult`, `HealthStatus`, `HealthConfig`, `HealthMiddleware`; `Registry`, `MetricsStorage` и его реализации, `MetricsConfig`, `MetricsMiddleware` |
| OpenAPI (1.1) | классы `Attribute\*`, `OpenApiConfig`, `Generator`, `SchemaBuilder`, `OpenApiMiddleware` |

**Стабильные форматы.** Форма JSON Problem Details, формат сообщений `errors` вида `"поле: текст"`,
атрибуты `Set-Cookie` сессий, имена и аргументы консольных команд.

**Не покрывается.** Всё с `@internal` (парсеры, компиляторы, матчеры, планировщик рефлексии,
`AppBuilder`, `ErrorPage`, `DocTypes`, базовые классы генераторов), сгенерированные файлы кэшей
(`container.php`, `routes.php`, `discovery.php`, `preload.php`: пересобирайте их `keel:compile`
после каждого обновления), HTML страницы ошибки, формулировки строк лога и скрипты бенчмарков.

**Версии PHP.** Минимальная версия поднимается только в мажорном релизе; новые версии PHP
добавляются в CI по мере выхода.

**Deprecation** объявляются в changelog и помечаются `#[\Deprecated]` с указанием замены.
