<?php

declare(strict_types=1);

namespace Keel\Tests\Testing;

use Keel\Container\Definitions;
use Keel\Kernel\Kernel;
use Keel\Testing\TestClient;
use Keel\Testing\TestResponse;
use Keel\Tests\Kernel\Fixtures\Clock;
use Keel\Tests\Kernel\Fixtures\RequestCounter;
use Keel\Tests\Kernel\Fixtures\UserController;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TestClient::class)]
#[CoversClass(TestResponse::class)]
final class TestClientTest extends TestCase
{
    private Kernel $kernel;

    private TestClient $client;

    protected function setUp(): void
    {
        $definitions = new Definitions();
        $definitions->set(Clock::class);
        $this->kernel = Kernel::build($definitions, [UserController::class]);
        $this->client = TestClient::forKernel($this->kernel);
    }

    public function testGetWithQueryAndJsonAssertions(): void
    {
        $this->client->get('/api/users/search?q=php&page=2&exact=1')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson(['q' => 'php', 'page' => 2])
            ->assertJsonPath('exact', true)
            ->assertJsonMissing('nope');
    }

    public function testJsonPostAndProblemDetails(): void
    {
        $this->client->json('POST', '/api/users', ['name' => 'Ann', 'email' => 'a@b.c', 'address' => ['city' => 'Oslo', 'zip' => '1']])
            ->assertStatus(201)
            ->assertJson(['name' => 'Ann', 'address' => ['city' => 'Oslo']]);

        $this->client->json('POST', '/api/users', ['name' => 'x'])
            ->assertProblem(400, 'Invalid request data', ['email: field is required']);

        $this->client->get('/nope')->assertProblem(404);
    }

    public function testFormBodyHeadersCookiesAndTerminate(): void
    {
        $response = $this->client->put('/api/users/3', ['k' => 'v']);
        $response->assertOk()->assertJsonPath('body.k', 'v');

        $this->client->withHeader('X-Token', 'secret')->get('/api/users/secret')->assertOk()->assertBodyContains('top secret');
        $this->client->get('/api/users/secret')->assertStatus(401)->assertHeader('WWW-Authenticate', 'Token');

        $resets = $this->kernel->container->get(RequestCounter::class)->resets;
        $this->client->get('/api/users/1');
        $this->client->get('/api/users/1');
        self::assertSame($resets + 2, $this->kernel->container->get(RequestCounter::class)->resets, 'terminate() after every request');

        $this->client->delete('/api/users/1')->assertStatus(204)->assertHeaderMissing('Content-Type');
    }

    public function testFailedAssertionShowsBodyExcerpt(): void
    {
        try {
            $this->client->get('/api/users/1')->assertStatus(500);
            self::fail('expected failure');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('Expected status 500, got 200. Body: {"id":1', $e->getMessage());
        }
    }
}
