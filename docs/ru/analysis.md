# Анализ микро-фреймворков PHP (октябрь 2026)

Снимок состояния на 2026-10-21. Версии и даты проверены по GitHub Releases, Packagist, php.net и
официальным блогам. Цель документа — зафиксировать, что сейчас есть на рынке, какие идеи
прижились, где слабые места, и из этого вывести требования к собственному фреймворку
(см. [design.md](design.md)).

## 1. Сводная таблица

| Фреймворк | Версия / дата | min PHP | PSR | Роутинг | DI | Долгоживущие рантаймы | Активность |
|---|---|---|---|---|---|---|---|
| Slim 4 | 4.15.3 / 09.2026 (Slim 5 — только roadmap) | 7.4 | 7/15/17/11/3 | FastRoute 1.3, closures | внешний PSR-11 (PHP-DI) | нет официального раннера; в 4.15.3 «fixes for long-lived runtime» | 12.3k★, по факту один мейнтейнер (akrabat) |
| Mezzio 3 | 3.28.1 / 06.2026 | 8.2 | 7/15/17/11 | FastRoute (файловый кэш), laminas-router, Aura | любой PSR-11 | mezzio-swoole 4.14; RR/FrankenPHP нет | 397★, в основном renovate-бот и 1–2 человека |
| Tempest 3 | 3.19.2 / 09.2026 | **8.5** | 11, 7/17 внутри; middleware не PSR-15 | атрибуты `#[Get]` + discovery с кэшем | свой, autowiring, Initializer, `#[Singleton]` | worker mode с 3.14; RR — отказ | 2.3k★, ядро 4–5 человек, релизы еженедельно |
| Spiral 3 | 3.17.2 / 08.2026 | 8.1 (app: 8.4) | 7/15/17/11/14/3/16 | `#[Route]` через Tokenizer (статический скан) | свой, autowiring | **RoadRunner** (часть архитектуры); fpm только через sapi-bridge | 2.1k★, коммиты — бампы Rector; блог мёртв с 2024 |
| Hyperf 3.2 | 3.2.4 / 08.2026 | 8.2 | 7/15/11/14/3/16 | FastRoute + `#[Controller]`/`#[GetMapping]` | свой, `#[Inject]`, AOP-прокси | **только Swoole/Swow** | 6.9k★, 450+ открытых issue, китаецентричная экосистема |
| Leaf 5 | 5.0.1 / 10.2026 | 8.2 | нет | `app()->get()`, индексированные маршруты | service locator без autowiring | нет | 1.3k★, bus factor 1 |
| Flight 3 | 3.19.4 / 10.2026 | 7.4 | нет (zero deps) | `Flight::route()` | мини-контейнер, рекомендуют Dice/PHP-DI | `flightphp/async` на Swoole | 2.9k★, 2 человека; CVE-2026-42551 |
| Fat-Free | 3.9.3 / 07.2026; 4.0 в alpha с 2025 | 7.x (4.0: 8.4) | нет | строки `'GET /x'`, ini | hive + Prefab | нет | один мейнтейнер, 4.0 застряла в альфе |
| Symfony MicroKernel | 7.4 LTS / 8.1.9 | 8.2 / **8.4** | HttpFoundation (PSR-7 через bridge), 11 | `#[Route]`, скомпилированный matcher | свой, компилируемый | **FrankenPHP worker нативно с 7.4** (symfony/runtime), RR | 31k★ |
| Laravel 13 + Octane | 13 / 03.2026; Octane 2.22 | 8.3 | свои абстракции | closures + `route:cache`, атрибуты `#[Middleware]` | свой | FrankenPHP, Swoole, RR | 35k★; Lumen фактически мёртв (последний релиз 12.2024) |
| Yii3 | релиз 31.12.2025, 130+ пакетов | 8.2 | 3/7/11/14/15/16/17 | yiisoft/router + fastroute | yiisoft/di, runtime без компиляции | yii-runner-frankenphp 1.0 (04.2026), RR, Swoole | ядро 2 человека, активность высокая |
| CodeIgniter 4 | 4.7.5 / 10.2026 | 8.2 | нет | `Routes.php`, атрибуты с 4.7 | Services-локатор | экспериментальный FrankenPHP worker (4.7) | 6k★ |

Новички 2025–2026: Hypervel (Laravel-стиль на Swoole, 0.3.x), webman 2.x (Workerman 5, Китай),
Pop PHP 7 (перепись под 8.4, один автор). Заметного «FrankenPHP-native» фреймворка не появилось:
FrankenPHP встраивают в существующие.

## 2. Что видно по каждой группе

### PSR-клей (Slim, Mezzio)
Самая чистая архитектура: PSR-7/15/17, контейнер снаружи, роутер — FastRoute. Проблема не в
дизайне, а в людях: Slim держится на одном человеке, Slim 5 обсуждается с 2022 без дат, две
security-дыры подряд в 2026 (CVE-2026-48157, GHSA-h377-p8x2-prf9). Mezzio технически живой,
но сообщества почти нет. Ни у одного нет официального worker-раннера.

### Attribute/discovery-фреймворки (Tempest, Spiral)
Tempest — единственный, кто довёл discovery до конца: нет файла роутов и провайдеров, всё
находится сканированием и кэшируется в проде. Цена — PHP 8.5-only, частые breaking changes и
производительность: issue #2309 (10.2026) показывает ~113 rps против ~27 000 у Symfony на том же
JSON-эндпоинте в FrankenPHP worker; подозревают роутер и контейнер. Spiral — зрелый PSR-стек и
атрибутный роутинг, но прибит к RoadRunner и выглядит как maintenance mode.

### Zero-dependency (Flight, Leaf, Fat-Free)
Делают ставку на «ничего не тянем» и отказываются от PSR. Это даёт малый размер, но изолирует
от экосистемы middleware и клиентов. Все три — bus factor 1–2. Новая ось маркетинга 2026 —
«AI-friendly DX» (Leaf: `CONTEXT.md`, Alchemy; Flight: генерация `AGENTS.md`).

### Coroutine-мир (Hyperf, webman, Hypervel)
Отдельная экосистема, в основном китайская. Требует Swoole/Swow, всё — синглтоны, per-request
состояние только через контекст корутины. Для западного рынка остаётся нишей.

### «Микро» от больших (Symfony MicroKernel, Laravel+Octane, Yii3)
Symfony 7.4+ с нативным FrankenPHP worker де-факто закрыл нишу «микро-API с компилируемым
контейнером». Yii3 после 7 лет вышел с полным PSR-стеком и раннерами для всех рантаймов.
Laravel «микро» теперь означает полный Laravel + Octane. Ниша для нового микро-фреймворка —
между «Slim, но живой и worker-first» и «Symfony, но без 40 пакетов».

## 3. Язык и рантаймы

- **PHP 8.4** (11.2024): property hooks, asymmetric visibility, lazy objects
  (`ReflectionClass::newLazyGhost/newLazyProxy`), `new Foo()->bar()`, `#[\Deprecated]`.
  Symfony 8 перешёл на нативные lazy objects и выкинул свои прокси.
- **PHP 8.5** (11.2025): pipe `|>`, `clone($o, [...])`, `ext/uri`, `#[\NoDiscard]`,
  замыкания и first-class callables в константных выражениях (значит, в аргументах атрибутов).
- **PHP 8.6** (план 19.11.2026): partial function application, `clamp()`, readonly defaults.
  True Async RFC отменён; async остаётся на Fibers + Revolt/AMPHP.
- Распространение (Packagist, 07.2026): 8.4 — 32 %, 8.3 — 24 %, 8.5 — 16 %, 8.2 — 14 %.
- **FrankenPHP** — проект PHP Foundation с 06.2025 (`github.com/php/frankenphp`), v1.13.1
  (10.2026), встроенный Mercure, 103 Early Hints. Worker mode стал нормой: Symfony 7.4, Yii3,
  CI4, Tempest, Octane. CVE-2026-24894 (утечка session id между запросами) показывает новый
  класс багов «состояние между запросами».
- **RoadRunner 3.0** (10.2026), PHP SDK `spiral/roadrunner-http` 4.x. Ниша Spiral/Octane/Yii3.
- **Swoole 6.2 / OpenSwoole 26.2** — раскол сохраняется. **NGINX Unit архивирован** (10.2025).
- **php-fpm** остаётся дефолтом деплоя; worker-режимы — опция.

## 4. Стандарты и готовые компоненты

PSR: приняты 1, 3, 4, 6, 7 (2.0), 11 (2.0), 12, 13, 14, 15, 16, 17, 18, 20; в драфте 21
(i18n) и 22 (tracing). Новых принятых PSR с 2022 нет; FIG работает над PER-CS 3.x.

| Что | Брать | Комментарий |
|---|---|---|
| PSR-7/17 | nyholm/psr7 1.8 + nyholm/psr7-server | минимальный, рекомендован FrankenPHP; заменяем на diactoros/guzzle через PSR-17 |
| PSR-3 | monolog 3.12 | опционально |
| Консоль | symfony/console 8.x | де-факто стандарт, без других symfony-зависимостей |
| PSR-14 | symfony/event-dispatcher или свой | league/event заморожен с 2024 |
| Роутер | FastRoute 1.x (PHP ≥5.4, без типов; 2.0 так и не вышла), league/route 7, symfony/routing 8 | все три компромиссны для «микро» |
| DI | php-di 7.1 (не компилирует autowired-классы вне конфига), symfony/di (тяжёл), league/container 5 | свой контейнер в 400–600 строк окупается |
| Конфиг | cuyz/valinor 2.6 (гидрация typed-объектов) | или свой минимальный маппер |

## 5. Тенденции, которые нужно учесть

1. Worker mode стал нормой, FrankenPHP — дефолт. Фреймворк должен быть stateless по
   конструкции: без статиков и глобального реестра, с явным сбросом сервисов.
2. Минимальный PHP поднялся: новые мажоры берут 8.4 (Symfony 8, Pop 7) или 8.5 (Tempest).
3. Атрибуты вместо конфигов роутинга и middleware — везде, включая Laravel 13 и CI4.
   Discovery (Tempest) — следующий шаг, но без ограничения скоупа и с медленной реализацией
   он даёт 113 rps.
4. Компиляция контейнера и роутов в PHP-файлы по-прежнему нужна: под fpm это основной
   выигрыш, под worker — быстрый старт и предсказуемость.
5. Security-волна 2026 у микро-фреймворков (Slim ×2, Flight, FrankenPHP, Spiral): тесты на
   method override, плейсхолдеры роутов, утечки между запросами — обязательная часть CI.
6. Bus factor: почти все микро-фреймворки держатся на 1–2 людях. Для собственного
   фреймворка это аргумент за минимальное ядро и максимум готовых PSR-компонентов.
7. «AI-friendly DX» (AGENTS.md, контекстные файлы, ошибки с копированием в Markdown) —
   дешёвая, но заметная фича.

## 6. Вывод

Свободная ниша: **PSR-стек как у Slim, атрибуты и ограниченный discovery как у Tempest,
компиляция как у Symfony, worker-first как у Yii3, и всё это в ядре на несколько тысяч строк
с минимумом зависимостей и минимумом PHP 8.4.** Ни один существующий фреймворк не закрывает
эту комбинацию целиком. Проектные решения — в [design.md](design.md).

## Источники

- https://www.php.net/releases/8.4/en.php · https://www.php.net/releases/8.5/index.php ·
  https://wiki.php.net/todo/php86 · https://php.watch/rfcs/true_async
- https://github.com/php/frankenphp/releases · https://frankenphp.dev/docs/worker/ ·
  https://thephp.foundation/blog/2025/05/15/frankenphp/ ·
  https://github.com/roadrunner-server/roadrunner/releases/tag/v3.0.0 · https://unit.nginx.org/
- https://www.php-fig.org/psr/ · https://symfony.com/8 · https://symfony.com/releases ·
  https://laravel.com/docs/13.x/releases · https://www.yiiframework.com/news/777/yii3-is-released
- https://www.slimframework.com/2026/08/31/slim-4.15.3-release.html ·
  https://github.com/slimphp/Slim/wiki/Slim-5-Road-Map · https://docs.mezzio.dev/mezzio/ ·
  https://getlaminas.org/blog/2026-03-06-laminas-mvc-eol-schedule.html
- https://tempestphp.com/blog/tempest-3 · https://tempestphp.com/main/internals/discovery ·
  https://github.com/tempestphp/tempest-framework/issues/2309
- https://spiral.dev/docs/http-routing/current/en · https://github.com/hyperf/hyperf/releases ·
  https://blog.leafphp.dev/posts/leaf-5 · https://github.com/flightphp/core/releases ·
  https://github.com/f3-factory/fatfree-core/releases ·
  https://codeigniter.com/user_guide/installation/worker_mode.html
- https://php-di.org/doc/performances.html · https://github.com/nikic/FastRoute/releases ·
  https://stitcher.io/blog/php-version-stats-july-2026 (вторичный)
