# keel/validation

Attribute rules implementing `Keel\Validation\Rule::check(mixed): ?string`: `NotBlank`, `Length`,
`Range`, `Count`, `Pattern`, `Alnum`, `Email`, `Url`, `Uuid`, `Ip`, `Hostname`, `Date`, `Json`,
`Timezone`, `Choice`, `Unique` and `Each` (rules per array item). The Keel hydrator runs them on DTO
constructor parameters; any code can call `check()` directly. Read-only split of
[AloneCoder/keel](https://github.com/AloneCoder/keel).
