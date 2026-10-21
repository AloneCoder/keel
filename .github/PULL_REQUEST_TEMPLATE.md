## What

<!-- One paragraph: the change and why. Link the issue when there is one. -->

## Checklist

- [ ] Tests added or updated in `tests/<Component>` (and `tests/Security` for security-relevant fixes)
- [ ] `composer test`, `composer stan`, `composer cs` pass locally
- [ ] Docs updated in `docs/en` and `docs/ru` for public API changes
- [ ] `CHANGELOG.md` has a line under "Unreleased"
- [ ] No static mutable state; per-request state lives in `Resettable` services
