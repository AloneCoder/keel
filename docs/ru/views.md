# Шаблоны

Пространство имён `Keel\View`. Для HTML контроллер возвращает строку (`Responder` отвечает
`text/html`); `ViewRenderer` превращает шаблон в эту строку. Keel подключает Twig или Latte,
оба — опциональные зависимости.

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
    cache: $projectDir . '/var/cache/twig',   // null: без скомпилированного кэша (разработка)
    debug: $env->bool('APP_DEBUG'),           // включает и auto_reload
    strictVariables: true,
    globals: ['app_name' => 'My app'],
);

// config/services.php
return new ServicesConfig([TwigProvider::class]);
```

`TwigProvider` регистрирует `Twig\Environment` (внедряйте его, чтобы добавить расширения,
фильтры или функции в своём провайдере) и `TwigRenderer` как `ViewRenderer`. Twig экранирует
HTML по умолчанию.

## Latte

```bash
composer require latte/latte
```

```php
// config/views.php
return static fn(Env $env, string $projectDir): LatteConfig => new LatteConfig(
    directory: $projectDir . '/templates',
    tempDirectory: $projectDir . '/var/cache/latte',  // null: компиляция в памяти процесса
    autoRefresh: $env->bool('APP_DEBUG'),
);

// config/services.php
return new ServicesConfig([LatteProvider::class]);
```

`LatteProvider` регистрирует `Latte\Engine` и `LatteRenderer`. Имена шаблонов — относительно
каталога. Latte экранирует по контексту (HTML, атрибуты, JavaScript) по умолчанию.

## Worker mode

Один движок обслуживает все запросы воркера, поэтому скомпилированные шаблоны остаются
загруженными: первый рендер на воркере компилирует, остальные переиспользуют. Не кладите в
глобальные переменные шаблонов данные запроса; передавайте их параметрами.

## Переводы

Если зарегистрирован `Translator` ([Локализация](i18n.md)), оба провайдера добавляют фильтр и
функцию `trans` и `locale()`: `{{ 'cart.items'|trans({count: 3}) }}`.

## Другие движки

Реализуйте `ViewRenderer` поверх своего движка и назначьте алиас в провайдере:

```php
$definitions->set(BladeRenderer::class)->alias(ViewRenderer::class);
```
