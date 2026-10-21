# Тестирование

`Keel\Testing\TestClient` гоняет запросы через ядро без SAPI и сокетов, а `TestResponse` даёт
цепочку ассертов PHPUnit. После каждого запроса вызывается `Kernel::terminate()`, как в воркере.

```php
final class UsersTest extends TestCase
{
    private TestClient $client;

    protected function setUp(): void
    {
        $app = App::boot(dirname(__DIR__), new Env(['APP_ENV' => 'test', 'APP_DEBUG' => '1']));
        $this->client = TestClient::forApp($app);
    }

    public function testCreate(): void
    {
        $this->client->json('POST', '/api/users', ['name' => 'Ann', 'email' => 'a@b.c'])
            ->assertStatus(201)
            ->assertJson(['name' => 'Ann'])
            ->assertJsonPath('address.city', null);

        $this->client->json('POST', '/api/users', ['name' => ''])
            ->assertProblem(400, 'Invalid request data', ['name: must not be blank', 'email: field is required']);
    }
}
```

| Метод клиента | Что делает |
|---|---|
| `get`, `delete` | запрос без тела |
| `post`, `put`, `patch` | массив → `application/x-www-form-urlencoded` и `getParsedBody()`, строка → сырое тело |
| `json($method, $uri, $data)` | JSON-тело с заголовками `Content-Type` и `Accept` |
| `request($method, $uri, $headers, $body)` | общий случай |
| `withHeader`, `withCookie`, `withServer` | значения по умолчанию для следующих запросов, возвращают новый клиент |

Ассерты `TestResponse`: `assertStatus`, `assertOk`, `assertHeader`, `assertHeaderMissing`,
`assertBodyContains`, `assertJson` (подмножество, рекурсивно), `assertJsonPath('a.b', value)`,
`assertJsonMissing`, `assertProblem($status, $detail, $errors)`. Доступ к данным: `status()`,
`header()`, `body()`, `json()`, `jsonPath()`, `response`.

В debug-режиме ядро не кэширует ничего, кроме discovery, поэтому `APP_DEBUG=1` в тестах
избавляет от устаревших `routes.php` и `container.php`. Контейнер в debug строится на
рефлексии, но поведение совпадает со скомпилированным: оба проходят один набор тестов.
