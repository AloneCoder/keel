# Testing

`Keel\Testing\TestClient` sends requests through the kernel without a SAPI or sockets, and
`TestResponse` offers chained PHPUnit assertions. `Kernel::terminate()` runs after every
request, exactly like in a worker.

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

| Client method | What it does |
|---|---|
| `get`, `delete` | request without a body |
| `post`, `put`, `patch` | array → `application/x-www-form-urlencoded` and `getParsedBody()`, string → raw body |
| `json($method, $uri, $data)` | JSON body with `Content-Type` and `Accept` headers |
| `request($method, $uri, $headers, $body)` | the general case |
| `withHeader`, `withCookie`, `withServer` | defaults for the following requests, return a new client |

`TestResponse` assertions: `assertStatus`, `assertOk`, `assertHeader`, `assertHeaderMissing`,
`assertBodyContains`, `assertJson` (subset, recursive), `assertJsonPath('a.b', value)`,
`assertJsonMissing`, `assertProblem($status, $detail, $errors)`. Data access: `status()`,
`header()`, `body()`, `json()`, `jsonPath()`, `response`.

In debug mode the kernel caches nothing but discovery, so `APP_DEBUG=1` in tests avoids stale
`routes.php` and `container.php`. The debug container runs on reflection but behaves exactly
like the compiled one: both pass the same test suite.
