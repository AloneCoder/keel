# API stability

From 1.0.0 Keel follows semantic versioning. What the promise covers:

**Stable: everything public that is not marked `@internal`.** Classes, methods, attributes,
configuration objects, exceptions and the formats below. Changing them in a breaking way means
a new major version, with a deprecation in the previous minor where feasible.

| Area | Stable entry points |
|---|---|
| Container | `Definitions`, `Definition`, `Container`, `Compiler`, `ContainerInterface`, `Resettable`, `ServiceProvider`, `Ref`, the `Attribute\*` classes, the `Exception\*` classes |
| Routing | `Router`, `Route`, `RouteMatch`, `MatchResult`, `MatchStatus`, the `Attribute\*` classes, `Exception\RoutingException` |
| HTTP | `Pipeline`, `CallableHandler`, `Responder`, `ArgumentResolver`, `Hydrator`, `EmitterInterface`, `SapiEmitter`, the `Attribute\*`, `Exception\*` and `Middleware\*` classes |
| Validation | `Rule` and every rule attribute |
| Kernel | `Kernel`, `ExceptionHandlerMiddleware`, `ProblemDetails` |
| Runtime | `RuntimeInterface`, `Runtime`, `FpmRuntime`, `FrankenPhpRuntime`, `RoadRunnerRuntime` |
| App | `App` (except `@internal` members), `Config\*`, `Discovery\DiscoveryInterface`, `Discoverer` and the built-in discoveries, `Event\*`, `Log\*`, `Session\*`, `Testing\*`, `Console\ConsoleFactory` |

**Stable formats.** The Problem Details JSON shape, the `errors` message format `"field: message"`,
the `Set-Cookie` attributes of sessions, the console command names and arguments.

**Not covered.** Anything marked `@internal` (parsers, compilers, matchers, the reflection planner,
`AppBuilder`, `ErrorPage`, generator base classes), the generated cache files (`container.php`,
`routes.php`, `discovery.php`, `preload.php`: rebuild them with `keel:compile` after every upgrade),
the HTML of the error page, log line wording, and benchmark scripts.

**PHP versions.** The minimum PHP version is raised only in a major release; new PHP versions are
added to CI as they are released.

**Deprecations** are announced in the changelog and carry `#[\Deprecated]` with the replacement.
