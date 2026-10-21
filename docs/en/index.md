# Keel

A small, worker-first PHP micro-framework: PSR-7/11/14/15/17 all the way, attribute-based routing
with scoped discovery, a compiled container and router, and the same code running under php-fpm,
FrankenPHP worker mode and RoadRunner. PHP >= 8.4.

```bash
composer create-project keel/skeleton my-app
cd my-app && composer serve
```

- [Design decisions](design.md) explain why the framework looks the way it does.
- [Container](container.md), [Routing](routing.md), [HTTP and kernel](http.md), [Runtimes](runtime.md),
  [Application](app.md), [Sessions](session.md), [Testing](testing.md) cover each component.
- [API stability](api.md) states what semantic versioning covers from 1.0.

Source, issues and benchmarks: [github.com/AloneCoder/keel](https://github.com/AloneCoder/keel).
