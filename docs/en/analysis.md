# PHP micro-framework landscape (October 2026)

Snapshot as of 2026-10-21. Versions and dates were checked against GitHub Releases, Packagist,
php.net and official blogs. The goal of this document is to record what is on the market now,
which ideas have taken hold, where the weak spots are, and to derive from that the requirements
for our own framework (see [design.md](design.md)).

## 1. Summary table

| Framework | Version / date | min PHP | PSR | Routing | DI | Long-running runtimes | Activity |
|---|---|---|---|---|---|---|---|
| Slim 4 | 4.15.3 / 09.2026 (Slim 5 — roadmap only) | 7.4 | 7/15/17/11/3 | FastRoute 1.3, closures | external PSR-11 (PHP-DI) | no official runner; 4.15.3 has "fixes for long-lived runtime" | 12.3k★, effectively a single maintainer (akrabat) |
| Mezzio 3 | 3.28.1 / 06.2026 | 8.2 | 7/15/17/11 | FastRoute (file cache), laminas-router, Aura | any PSR-11 | mezzio-swoole 4.14; no RR/FrankenPHP | 397★, mostly the renovate bot and 1–2 people |
| Tempest 3 | 3.19.2 / 09.2026 | **8.5** | 11, 7/17 internally; middleware is not PSR-15 | `#[Get]` attributes + discovery with cache | own, autowiring, Initializer, `#[Singleton]` | worker mode since 3.14; RR — declined | 2.3k★, core of 4–5 people, weekly releases |
| Spiral 3 | 3.17.2 / 08.2026 | 8.1 (app: 8.4) | 7/15/17/11/14/3/16 | `#[Route]` via Tokenizer (static scan) | own, autowiring | **RoadRunner** (part of the architecture); fpm only via a sapi bridge | 2.1k★, commits are Rector bumps; blog dead since 2024 |
| Hyperf 3.2 | 3.2.4 / 08.2026 | 8.2 | 7/15/11/14/3/16 | FastRoute + `#[Controller]`/`#[GetMapping]` | own, `#[Inject]`, AOP proxies | **Swoole/Swow only** | 6.9k★, 450+ open issues, China-centric ecosystem |
| Leaf 5 | 5.0.1 / 10.2026 | 8.2 | none | `app()->get()`, indexed routes | service locator without autowiring | no | 1.3k★, bus factor 1 |
| Flight 3 | 3.19.4 / 10.2026 | 7.4 | none (zero deps) | `Flight::route()` | mini container, Dice/PHP-DI recommended | `flightphp/async` on Swoole | 2.9k★, 2 people; CVE-2026-42551 |
| Fat-Free | 3.9.3 / 07.2026; 4.0 in alpha since 2025 | 7.x (4.0: 8.4) | none | `'GET /x'` strings, ini | hive + Prefab | no | single maintainer, 4.0 stuck in alpha |
| Symfony MicroKernel | 7.4 LTS / 8.1.9 | 8.2 / **8.4** | HttpFoundation (PSR-7 via bridge), 11 | `#[Route]`, compiled matcher | own, compiled | **FrankenPHP worker natively since 7.4** (symfony/runtime), RR | 31k★ |
| Laravel 13 + Octane | 13 / 03.2026; Octane 2.22 | 8.3 | own abstractions | closures + `route:cache`, `#[Middleware]` attributes | own | FrankenPHP, Swoole, RR | 35k★; Lumen effectively dead (last release 12.2024) |
| Yii3 | released 31.12.2025, 130+ packages | 8.2 | 3/7/11/14/15/16/17 | yiisoft/router + fastroute | yiisoft/di, runtime without compilation | yii-runner-frankenphp 1.0 (04.2026), RR, Swoole | core of 2 people, high activity |
| CodeIgniter 4 | 4.7.5 / 10.2026 | 8.2 | none | `Routes.php`, attributes since 4.7 | Services locator | experimental FrankenPHP worker (4.7) | 6k★ |

Newcomers of 2025–2026: Hypervel (Laravel-style on Swoole, 0.3.x), webman 2.x (Workerman 5,
China), Pop PHP 7 (rewrite for 8.4, single author). No notable "FrankenPHP-native" framework has
appeared: FrankenPHP is being embedded into existing ones.

## 2. Observations by group

### PSR glue (Slim, Mezzio)
The cleanest architecture: PSR-7/15/17, container on the outside, FastRoute as the router. The
problem is not the design but the people: Slim depends on one person, Slim 5 has been discussed
since 2022 with no dates, and there were two consecutive security holes in 2026
(CVE-2026-48157, GHSA-h377-p8x2-prf9). Mezzio is technically alive but has almost no community.
Neither has an official worker runner.

### Attribute/discovery frameworks (Tempest, Spiral)
Tempest is the only one that took discovery all the way: there is no routes file and no
providers; everything is found by scanning and cached in prod. The price is PHP 8.5-only,
frequent breaking changes and performance: issue #2309 (10.2026) shows ~113 rps versus ~27,000
for Symfony on the same JSON endpoint in FrankenPHP worker; the router and the container are
suspected. Spiral has a mature PSR stack and attribute routing, but is tied to RoadRunner and
looks like it is in maintenance mode.

### Zero-dependency (Flight, Leaf, Fat-Free)
They bet on "we pull in nothing" and give up on PSR. This yields a small footprint but isolates
them from the ecosystem of middleware and clients. All three have a bus factor of 1–2. A new
marketing axis in 2026 is "AI-friendly DX" (Leaf: `CONTEXT.md`, Alchemy; Flight: `AGENTS.md`
generation).

### Coroutine world (Hyperf, webman, Hypervel)
A separate, mostly Chinese ecosystem. It requires Swoole/Swow, everything is a singleton, and
per-request state is possible only through the coroutine context. It remains a niche for the
Western market.

### "Micro" from the big players (Symfony MicroKernel, Laravel+Octane, Yii3)
Symfony 7.4+ with a native FrankenPHP worker has de facto closed the "micro API with a compiled
container" niche. Yii3, after 7 years, shipped with a full PSR stack and runners for every
runtime. Laravel "micro" now means full Laravel + Octane. The niche for a new micro-framework is
between "Slim, but alive and worker-first" and "Symfony, but without 40 packages".

## 3. Language and runtimes

- **PHP 8.4** (11.2024): property hooks, asymmetric visibility, lazy objects
  (`ReflectionClass::newLazyGhost/newLazyProxy`), `new Foo()->bar()`, `#[\Deprecated]`.
  Symfony 8 moved to native lazy objects and dropped its own proxies.
- **PHP 8.5** (11.2025): pipe `|>`, `clone($o, [...])`, `ext/uri`, `#[\NoDiscard]`,
  closures and first-class callables in constant expressions (hence in attribute arguments).
- **PHP 8.6** (planned 19.11.2026): partial function application, `clamp()`, readonly defaults.
  The True Async RFC was withdrawn; async stays on Fibers + Revolt/AMPHP.
- Adoption (Packagist, 07.2026): 8.4 — 32 %, 8.3 — 24 %, 8.5 — 16 %, 8.2 — 14 %.
- **FrankenPHP** — a PHP Foundation project since 06.2025 (`github.com/php/frankenphp`), v1.13.1
  (10.2026), built-in Mercure, 103 Early Hints. Worker mode has become the norm: Symfony 7.4,
  Yii3, CI4, Tempest, Octane. CVE-2026-24894 (session id leaking between requests) shows a new
  class of bugs: "state between requests".
- **RoadRunner 3.0** (10.2026), PHP SDK `spiral/roadrunner-http` 4.x. The niche of
  Spiral/Octane/Yii3.
- **Swoole 6.2 / OpenSwoole 26.2** — the split persists. **NGINX Unit is archived** (10.2025).
- **php-fpm** remains the default deployment; worker modes are an option.

## 4. Standards and ready-made components

PSR: accepted are 1, 3, 4, 6, 7 (2.0), 11 (2.0), 12, 13, 14, 15, 16, 17, 18, 20; in draft are 21
(i18n) and 22 (tracing). No new PSRs have been accepted since 2022; FIG is working on PER-CS 3.x.

| What | Use | Comment |
|---|---|---|
| PSR-7/17 | nyholm/psr7 1.8 + nyholm/psr7-server | minimal, recommended by FrankenPHP; replaceable with diactoros/guzzle via PSR-17 |
| PSR-3 | monolog 3.12 | optional |
| Console | symfony/console 8.x | de facto standard, no other symfony dependencies |
| PSR-14 | symfony/event-dispatcher or own | league/event frozen since 2024 |
| Router | FastRoute 1.x (PHP ≥5.4, untyped; 2.0 never shipped), league/route 7, symfony/routing 8 | all three are a compromise for "micro" |
| DI | php-di 7.1 (does not compile autowired classes outside config), symfony/di (heavy), league/container 5 | an own container of 400–600 lines pays off |
| Config | cuyz/valinor 2.6 (typed object hydration) | or an own minimal mapper |

## 5. Trends to take into account

1. Worker mode has become the norm, FrankenPHP is the default. The framework must be stateless
   by construction: no statics and no global registry, with explicit service reset.
2. The minimum PHP has risen: new majors take 8.4 (Symfony 8, Pop 7) or 8.5 (Tempest).
3. Attributes instead of routing and middleware configs — everywhere, including Laravel 13 and
   CI4. Discovery (Tempest) is the next step, but without a bounded scope and with a slow
   implementation it yields 113 rps.
4. Compiling the container and routes into PHP files is still needed: under fpm it is the main
   gain, under worker it gives fast startup and predictability.
5. The 2026 security wave among micro-frameworks (Slim ×2, Flight, FrankenPHP, Spiral): tests
   for method override, route placeholders and cross-request leaks are a mandatory part of CI.
6. Bus factor: almost all micro-frameworks depend on 1–2 people. For our own framework this is
   an argument for a minimal core and a maximum of ready-made PSR components.
7. "AI-friendly DX" (AGENTS.md, context files, errors copyable as Markdown) is a cheap but
   noticeable feature.

## 6. Conclusion

The open niche: **a PSR stack like Slim's, attributes and bounded discovery like Tempest's,
compilation like Symfony's, worker-first like Yii3's, and all of it in a core of a few thousand
lines with minimal dependencies and a PHP 8.4 minimum.** No existing framework covers this
combination in full. The design decisions are in [design.md](design.md).

## Sources

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
  https://stitcher.io/blog/php-version-stats-july-2026 (secondary)
