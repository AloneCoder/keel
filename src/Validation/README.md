# keel/validation

Attribute rules implementing `Keel\Validation\Rule::check(mixed): ?string`: `NotBlank`, `Length`,
`Range`, `Count`, `Pattern`, `Email`, `Url`, `Choice`. The Keel hydrator runs them on DTO
constructor parameters; any code can call `check()` directly. Read-only split of
[AloneCoder/keel](https://github.com/AloneCoder/keel).
