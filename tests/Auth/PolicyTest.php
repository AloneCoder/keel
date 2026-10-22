<?php

declare(strict_types=1);

namespace Keel\Tests\Auth;

use Keel\Auth\Attribute\Can;
use Keel\Auth\AuthConfig;
use Keel\Auth\AuthMiddleware;
use Keel\Auth\BearerTokenAuthenticator;
use Keel\Auth\Gate;
use Keel\Auth\Identity;
use Keel\Auth\Policy;
use Keel\Auth\PolicyGuard;
use Keel\Auth\PrincipalResolver;
use Keel\Container\Definitions;
use Keel\Http\Exception\ForbiddenException;
use Keel\Http\Exception\HttpException;
use Keel\Kernel\Kernel;
use Keel\OpenApi\Generator;
use Keel\Routing\Attribute\Patch;
use Keel\Routing\Router;
use Keel\Testing\TestClient;
use Keel\Tests\Auth\Fixtures\PolicyController;
use Keel\Tests\Auth\Fixtures\PostPolicy;
use Keel\Tests\Auth\Fixtures\Tokens;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Gate::class)]
#[CoversClass(PolicyGuard::class)]
#[CoversClass(Can::class)]
#[CoversClass(ForbiddenException::class)]
#[CoversClass(HttpException::class)]
#[CoversClass(Patch::class)]
final class PolicyTest extends TestCase
{
    /**
     * @param array<mixed> $doc
     *
     * @return array<string, mixed>
     */
    private static function node(array $doc, string ...$keys): array
    {
        $value = $doc;
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value, 'missing ' . implode('.', $keys));
            $value = $value[$key];
        }
        self::assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }

    private static function client(bool $globalMiddleware): TestClient
    {
        $definitions = new Definitions();
        $definitions->set(Tokens::class)->alias(PrincipalResolver::class);
        $definitions->set(PostPolicy::class)->tag(Policy::TAG);
        $definitions->instance(AuthConfig::class, new AuthConfig([BearerTokenAuthenticator::class]));

        return TestClient::forKernel(Kernel::build($definitions, [PolicyController::class], $globalMiddleware ? [AuthMiddleware::class] : []));
    }

    public function testGateAsksThePolicyDeclaringTheAbility(): void
    {
        $gate = new Gate([new PostPolicy()]);
        $user = new Identity('u1', ['user']);
        $admin = new Identity('u2', ['admin']);

        self::assertSame(['post.view', 'post.update'], $gate->abilities());
        self::assertTrue($gate->allows($user, 'post.view'));
        self::assertTrue($gate->allows($admin, 'post.update', ['id' => '9']));
        self::assertTrue($gate->allows($user, 'post.update', (object) ['author' => 'u1']));
        self::assertTrue($gate->denies($user, 'post.update', (object) ['author' => 'u2']));
        $gate->authorize($user, 'post.view');

        try {
            $gate->authorize($user, 'post.update', (object) ['author' => 'u2']);
            self::fail('refusal expected');
        } catch (ForbiddenException $e) {
            self::assertSame(403, $e->status);
            self::assertSame('Not allowed to post.update', $e->getMessage());
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No policy declares the ability "post.publish"');
        $gate->allows($user, 'post.publish');
    }

    public function testTwoPoliciesForOneAbilityAreRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declared by both');
        new Gate([new PostPolicy(), new PostPolicy()]);
    }

    public function testCanGuardsTheRouteWithTheRouteParametersAsSubject(): void
    {
        $client = self::client(false);

        $client->put('/posts/1')->assertProblem(401, 'Authentication required');
        $client->put('/posts/1', [], ['Authorization' => 'Bearer user-token'])->assertOk()->assertJson(['updated' => 1, 'by' => 'u1']);
        $client->put('/posts/2', [], ['Authorization' => 'Bearer user-token'])->assertProblem(403, 'Not allowed to post.update');
        $client->put('/posts/2', [], ['Authorization' => 'Bearer admin-token'])->assertOk()->assertJson(['by' => 'u2']);

        $global = self::client(true);
        $global->patch('/posts/5', [], ['Authorization' => 'Bearer user-token'])->assertOk()->assertJson(['patched' => 5]);
        $global->patch('/posts/5', [], ['Authorization' => 'Bearer admin-token'])->assertOk();
        $global->put('/posts/2', [], ['Authorization' => 'Bearer user-token'])->assertProblem(403, 'Not allowed to post.update');
        $client->get('/posts/1', ['Authorization' => 'Bearer user-token'])->assertStatus(500);
    }

    public function testOpenApiDocumentsCanLikeAuth(): void
    {
        $document = new Generator(Router::fromControllers(PolicyController::class))->generate();
        $paths = (array) $document['paths'];
        $responses = self::node($paths, '/posts/{id}', 'put', 'responses');

        self::assertArrayHasKey('401', $responses);
        self::assertSame('Not allowed', self::node($responses, '403')['description']);
        self::assertArrayNotHasKey('401', self::node($paths, '/posts/{id}', 'patch', 'responses'));

        $this->expectException(\InvalidArgumentException::class);
        new Can('');
    }
}
