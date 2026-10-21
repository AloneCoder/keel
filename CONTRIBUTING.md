# Contributing

Thanks for looking into Keel. Bug reports, questions and pull requests are welcome.

## Before you start

- Check the [issues](https://github.com/AloneCoder/keel/issues) for an existing report.
- For a new feature, open an issue first and describe the use case. Keel is a micro-framework
  with a frozen 1.x API (see `docs/en/api.md`): things that belong in a package built on top of
  it (ORM, templating, queues) will be pointed there rather than merged.
- Security problems go through `SECURITY.md`, not the issue tracker.

## Setting up

```bash
git clone https://github.com/AloneCoder/keel.git
cd keel
composer install
composer test        # PHPUnit
composer stan        # PHPStan, level max
composer cs          # php-cs-fixer, dry run (composer cs-fix applies)
```

PHP 8.4 or newer is required. FrankenPHP is optional; `tests/Runtime` fakes the worker loop.

## Pull requests

- One change per pull request, on a branch from `main`.
- Tests for every behaviour change, in `tests/<Component>`; a bug fix includes the test that
  failed before the fix. Security-relevant fixes add a case to `tests/Security`.
- `composer test`, `composer stan` and `composer cs` must pass; CI runs them on PHP 8.4 and 8.5
  and runs Infection on the container and the router.
- Public API additions come with documentation in both `docs/en` and `docs/ru` and a line in
  `CHANGELOG.md` under "Unreleased". If you cannot write Russian, write the English page and
  say so; the translation will be added before the release.
- Keep the worker-mode rules: no static mutable state, per-request state in `Resettable`
  services, nothing loaded at boot that a request does not need.

## Code style

PER-CS via php-cs-fixer, `declare(strict_types=1)` everywhere, `final` by default, readonly
value objects, constructor promotion. Comments explain why, not what, and are written in
English. Commit messages are short imperative sentences ("Add HMAC authenticator").

## Component packages

`keel/container`, `keel/router`, `keel/http` and `keel/validation` are read-only subtree
splits of `src/<Component>`; send changes to this repository, never to the split repositories.
