<?php

declare(strict_types=1);

namespace Keel\Tests\Routing;

use Keel\Container\Definitions;
use Keel\Kernel\Kernel;
use Keel\OpenApi\Generator;
use Keel\Routing\Exception\RoutingException;
use Keel\Routing\MatchStatus;
use Keel\Routing\PathParser;
use Keel\Routing\Route;
use Keel\Routing\RouteCompiler;
use Keel\Routing\Router;
use Keel\Routing\UrlGenerator;
use Keel\Testing\TestClient;
use Keel\Tests\Routing\Fixtures\OptionalController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PathParser::class)]
#[CoversClass(RouteCompiler::class)]
#[CoversClass(UrlGenerator::class)]
#[CoversClass(Route::class)]
#[CoversClass(Generator::class)]
final class OptionalSegmentsTest extends TestCase
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

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function expansions(): iterable
    {
        yield 'plain' => ['/users/{id}', ['/users/{id}']];
        yield 'one optional' => ['/users[/{id}]', ['/users', '/users/{id}']];
        yield 'nested' => ['/users[/{id:\d+}[/{tab}]]', ['/users', '/users/{id:\d+}', '/users/{id:\d+}/{tab}']];
        yield 'literal segment' => ['/docs[/latest]', ['/docs', '/docs/latest']];
        yield 'class in a constraint is not a marker' => ['/tags/{slug:[a-z]+}', ['/tags/{slug:[a-z]+}']];
        yield 'constraint inside optional' => ['/a[/{b:[0-9]+}]', ['/a', '/a/{b:[0-9]+}']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('expansions')]
    public function testExpandsOptionalSegments(string $path, array $expected): void
    {
        self::assertSame($expected, new PathParser()->expand($path));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function malformed(): iterable
    {
        yield 'not trailing' => ['/a[/b]/c'];
        yield 'sibling groups' => ['/a[/b][/c]'];
        yield 'unbalanced open' => ['/a[/b'];
        yield 'unbalanced close' => ['/a/b]'];
        yield 'empty' => ['/a[]'];
    }

    #[DataProvider('malformed')]
    public function testMalformedOptionalSegmentsAreRejected(string $path): void
    {
        $this->expectException(RoutingException::class);
        new PathParser()->expand($path);
    }

    public function testEveryVariantMatchesTheSameRoute(): void
    {
        $router = Router::fromControllers(OptionalController::class);

        $list = $router->match('GET', '/files/users');
        self::assertSame(MatchStatus::Found, $list->status);
        self::assertNotNull($list->match);
        self::assertSame('users', $list->match->route->name);
        self::assertSame([], $list->match->params);

        self::assertSame(['id' => '5'], $router->match('GET', '/files/users/5')->match?->params);
        self::assertSame(['id' => '5', 'tab' => 'posts'], $router->match('GET', '/files/users/5/posts')->match?->params);
        self::assertSame('files', $router->match('GET', '/files/users/x')->match?->route->name, 'the constraint still applies: the catch-all declared last takes it');

        self::assertSame([], $router->match('GET', '/files')->match?->params, 'a wholly optional path attaches to the prefix');
        self::assertSame(['path' => 'a/b.txt'], $router->match('GET', '/files/a/b.txt')->match?->params);
        self::assertSame(['slug' => 'php'], $router->match('GET', '/files/tags/php')->match?->params);

        self::assertSame(['/files/users', '/files/users/{id}', '/files/users/{id}/{tab}'], $list->match->route->templates());
        self::assertSame('/files/users[/{id}[/{tab}]]', $list->match->route->template());
    }

    public function testGeneratesTheLongestVariantTheParametersAllow(): void
    {
        $router = Router::fromControllers(OptionalController::class);

        self::assertSame('/files/users', $router->generate('users'));
        self::assertSame('/files/users/5', $router->generate('users', ['id' => 5]));
        self::assertSame('/files/users/5/posts', $router->generate('users', ['id' => 5, 'tab' => 'posts']));
        self::assertSame('/files/users?tab=posts', $router->generate('users', ['tab' => 'posts']), 'a later optional without the earlier one goes to the query string');
        self::assertSame('/files/users', $router->generate('users', ['id' => null]), 'null means absent');
        self::assertSame('/files/a%2Fb', $router->generate('files', ['path' => 'a/b']));

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('is not a valid "id"');
        $router->generate('users', ['id' => 'x']);
    }

    public function testVariantsSurviveTheCacheAndHandlersGetDefaults(): void
    {
        $file = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/optional-' . uniqid() . '.php';
        Router::fromControllers(OptionalController::class)->write($file);
        $router = Router::fromCache($file);
        unlink($file);
        self::assertSame(['id' => '5'], $router->match('GET', '/files/users/5')->match?->params);

        $client = TestClient::forKernel(Kernel::build(new Definitions(), [OptionalController::class], router: $router));
        $client->get('/files/users')->assertOk()->assertJson(['id' => null, 'tab' => 'profile']);
        $client->get('/files/users/5/posts')->assertOk()->assertJson(['id' => 5, 'tab' => 'posts']);
        $client->get('/files')->assertOk()->assertJson(['path' => '']);
        $client->get('/files/a/b')->assertOk()->assertJson(['path' => 'a/b']);
    }

    public function testOpenApiDocumentsOnePathPerVariant(): void
    {
        $document = new Generator(Router::fromControllers(OptionalController::class))->generate();
        $paths = (array) $document['paths'];

        self::assertSame(['/files/users', '/files/users/{id}', '/files/users/{id}/{tab}', '/files/tags/{slug}', '/files', '/files/{path}'], array_keys($paths));
        self::assertArrayNotHasKey('parameters', self::node($paths, '/files/users', 'get'));
        $names = [];
        foreach (self::node($paths, '/files/users/{id}/{tab}', 'get', 'parameters') as $parameter) {
            $names[] = self::node((array) $parameter)['name'];
        }
        self::assertSame(['id', 'tab'], $names);
        self::assertSame('files', self::node($paths, '/files', 'get')['operationId']);
        self::assertSame('files2', self::node($paths, '/files/{path}', 'get')['operationId'], 'variants share the base id with a suffix');
    }
}
