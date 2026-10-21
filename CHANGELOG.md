# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow SemVer.
Until 1.0.0 minor versions may contain breaking changes.

## [0.1.0] - 2026-10-21

First release: the whole core, PHP >= 8.4.

### Added

- **Container** (`Keel\Container`): PSR-11 2.0, constructor autowiring, `#[Inject]`,
  `#[TaggedIterator]`, `#[Lazy]` (PHP 8.4 lazy ghosts and proxies), `#[Transient]`,
  `#[Alias]`, `#[Tag]`, `Resettable` services, `Ref` argument overrides, static and
  instance method factories, a compiler generating one method per service with a
  reflection fallback, and a shared behaviour test suite for both implementations.
- **Routing** (`Keel\Routing`): `#[Route]`, `#[Get]`/`#[Post]`/`#[Put]`/`#[Patch]`/`#[Delete]`,
  `#[Prefix]`, `#[Middleware]`, `#[Status]`; path placeholders with constraints; group-count
  regex compiler; matcher with 405 + `Allow` and HEAD fallback; URL generator; PHP-array cache.
- **HTTP** (`Keel\Http`): PSR-15 pipeline, HTTP exceptions, `Responder`, argument resolver
  with `#[FromQuery]`/`#[FromBody]`, DTO hydrator with per-field errors, body parser,
  opt-in `MethodOverrideMiddleware`, SAPI emitter.
- **Kernel** (`Keel\Kernel`): routing and dispatch handlers, RFC 9457 Problem Details
  exception handler, `Kernel::build()` with PSR-17 defaults, `terminate()` reset.
- **Runtimes** (`Keel\Runtime`): php-fpm, FrankenPHP worker mode, RoadRunner; `Runtime::detect()`.
- **App** (`Keel\App`, `Keel\Config`, `Keel\Discovery`, `Keel\Event`, `Keel\Console`):
  `App::boot()` with `.env`, typed config objects, scoped attribute discovery with cache,
  PSR-14 events with `#[Listener]`, Symfony Console integration with `keel:*` commands.
- Documentation in Russian and English, hello example, in-process benchmarks.
