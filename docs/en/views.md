# Templates

Namespace `Keel\View`. Controllers return strings for HTML (`Responder` answers
`text/html`); a `ViewRenderer` turns a template into that string. Keel wires Twig or Latte,
both optional dependencies.

```php
final class PageController
{
    public function __construct(private readonly ViewRenderer $views) {}

    #[Get('/')]
    public function home(): string
    {
        return $this->views->render('home.twig', ['title' => 'Hello']);
    }
}
```

## Twig

```bash
composer require twig/twig
```

```php
// config/views.php
return static fn(Env $env, string $projectDir): TwigConfig => new TwigConfig(
    paths: [$projectDir . '/templates'],
    cache: $projectDir . '/var/cache/twig',   // null: no compiled cache (development)
    debug: $env->bool('APP_DEBUG'),           // also enables auto_reload
    strictVariables: true,
    globals: ['app_name' => 'My app'],
);

// config/services.php
return new ServicesConfig([TwigProvider::class]);
```

`TwigProvider` registers `Twig\Environment` (inject it to add extensions, filters or
functions in a provider of your own) and `TwigRenderer` as the `ViewRenderer`. Twig
autoescapes HTML by default.

## Latte

```bash
composer require latte/latte
```

```php
// config/views.php
return static fn(Env $env, string $projectDir): LatteConfig => new LatteConfig(
    directory: $projectDir . '/templates',
    tempDirectory: $projectDir . '/var/cache/latte',  // null: compile in memory per process
    autoRefresh: $env->bool('APP_DEBUG'),
);

// config/services.php
return new ServicesConfig([LatteProvider::class]);
```

`LatteProvider` registers `Latte\Engine` and `LatteRenderer`. Template names are relative to
the directory. Latte escapes according to context (HTML, attributes, JavaScript) by default.

## Worker mode

One engine serves every request of a worker, so compiled templates stay loaded: the first
render on a worker compiles, the rest reuse. Keep template globals free of per-request data;
pass the request's data as parameters.

## Translations

With a `Translator` registered ([Localization](i18n.md)) both providers add the `trans` filter
and function and `locale()`: `{{ 'cart.items'|trans({count: 3}) }}`.

## Other engines

Implement `ViewRenderer` over the engine of your choice and alias it in a provider:

```php
$definitions->set(BladeRenderer::class)->alias(ViewRenderer::class);
```
