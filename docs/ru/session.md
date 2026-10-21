# Сессии и подписанные cookie

Namespace `Keel\Session`. Сессия живёт в атрибуте запроса, а не в сервисе, поэтому в worker-режиме
ничего не протекает между запросами. Подключение: `SessionMiddleware` в `HttpConfig` и
`SessionConfig` в `config/session.php`.

```php
// config/session.php
return static fn(Env $env): SessionConfig => new SessionConfig(
    secret: $env->require('APP_SECRET'),   // не короче 32 символов
    storage: 'cookie',                     // или 'file'
    ttl: 14 * 86400,
);

// контроллер: сессия приходит по типу параметра
#[Post('/login')]
public function login(#[FromBody] Login $cmd, Session $session): array
{
    $session->regenerate();                // новая id, защита от фиксации
    $session->set('user_id', $id);
    return ['ok' => true];
}
```

| Хранилище | Где данные | Когда подходит |
|---|---|---|
| `cookie` (по умолчанию) | в самой cookie, подписанной HMAC-SHA256, со сроком годности | нет серверного состояния, масштабируется горизонтально; данные видны клиенту (не секреты), лимит 4 КБ |
| `file` | `var/sessions/sess_<id>.json`, в cookie только подписанная id | данные скрыты от клиента, больше 4 КБ; нужна общая файловая система или одна нода |

Своё хранилище (Redis и т.п.): реализуйте `SessionStorageInterface` (`read`, `write`, `destroy`) и
передайте вторым аргументом `SessionMiddleware` через определение в провайдере.

API `Session`: `get`, `set`, `has`, `remove`, `pull` (flash), `all`, `clear`, `regenerate`,
`destroy`, `id`, `isNew`. Cookie ставится только если сессия менялась; уничтоженная или
опустевшая сессия снимает cookie (`Max-Age=0`) и удаляет запись в хранилище. Флаги cookie:
`HttpOnly`, `SameSite` из конфига (`Lax` по умолчанию), `Secure` автоматически для https.

`CookieSigner` можно использовать отдельно: `sign($value)` → `payload.signature`, `verify($cookie)`
→ значение или `null` при неверной подписи. Подходит для «remember me» и подписанных ссылок.

Инъекция по атрибуту работает для любого объекта: middleware кладёт объект в запрос под именем
его класса (`$request->withAttribute(Identity::class, $identity)`), и параметр `Identity $identity`
в хэндлере получает его без обращения к контейнеру.
