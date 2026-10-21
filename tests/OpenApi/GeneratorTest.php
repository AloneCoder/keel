<?php

declare(strict_types=1);

namespace Keel\Tests\OpenApi;

use Keel\Auth\AuthConfig;
use Keel\Auth\BearerTokenAuthenticator;
use Keel\Auth\PrincipalResolver;
use Keel\Container\Definitions;
use Keel\Http\Responder;
use Keel\Kernel\Kernel;
use Keel\OpenApi\Attribute\Field;
use Keel\OpenApi\Attribute\Hidden;
use Keel\OpenApi\Attribute\Operation;
use Keel\OpenApi\Attribute\Response;
use Keel\OpenApi\DocTypes;
use Keel\OpenApi\Generator;
use Keel\OpenApi\OpenApiConfig;
use Keel\OpenApi\OpenApiMiddleware;
use Keel\OpenApi\SchemaBuilder;
use Keel\Routing\Router;
use Keel\Testing\TestClient;
use Keel\Tests\Auth\Fixtures\Tokens;
use Keel\Tests\OpenApi\Fixtures\TaskController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Generator::class)]
#[CoversClass(SchemaBuilder::class)]
#[CoversClass(DocTypes::class)]
#[CoversClass(OpenApiConfig::class)]
#[CoversClass(OpenApiMiddleware::class)]
#[CoversClass(Operation::class)]
#[CoversClass(Response::class)]
#[CoversClass(Field::class)]
#[CoversClass(Hidden::class)]
final class GeneratorTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function document(?OpenApiConfig $config = null): array
    {
        $definitions = new Definitions();
        $definitions->set(Tokens::class)->alias(PrincipalResolver::class);
        $definitions->instance(AuthConfig::class, new AuthConfig([BearerTokenAuthenticator::class]));
        $definitions->instance(OpenApiConfig::class, $config ?? new OpenApiConfig('Tasks API', '2.0.0', 'Manage tasks', [['url' => 'https://api.example.com']]));
        $kernel = Kernel::build($definitions, [TaskController::class]);

        $document = $kernel->container->get(Generator::class)->generate();
        $json = json_decode(json_encode($document, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        /** @var array<string, mixed> $json */
        return $json;
    }

    /**
     * The array at a key path of the document; fails when a step is missing or not an array.
     *
     * @param array<string, mixed> $doc
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

    public function testDocumentSkeletonAndPaths(): void
    {
        $doc = $this->document();

        self::assertSame('3.1.0', $doc['openapi']);
        self::assertSame(['title' => 'Tasks API', 'version' => '2.0.0', 'description' => 'Manage tasks'], $doc['info']);
        self::assertSame([['url' => 'https://api.example.com']], $doc['servers']);
        self::assertSame(['/tasks', '/tasks/{id}', '/tasks/export'], array_keys(self::node($doc, 'paths')));
        self::assertSame(['get', 'post'], array_keys(self::node($doc, 'paths', '/tasks')));
        self::assertSame(['get', 'delete'], array_keys(self::node($doc, 'paths', '/tasks/{id}')));
        self::assertSame(['bearer' => ['type' => 'http', 'scheme' => 'bearer']], self::node($doc, 'components', 'securitySchemes'));
        self::assertSame(['CreateTask', 'Problem', 'Tag', 'TaskView'], array_keys(self::node($doc, 'components', 'schemas')));
    }

    public function testQueryDtoBecomesParametersAndListReturnTypeIsAnArrayOfRefs(): void
    {
        $op = self::node($this->document(), 'paths', '/tasks', 'get');

        self::assertSame('tasks.list', $op['operationId']);
        self::assertSame('List tasks', $op['summary']);
        self::assertSame(['Tasks'], $op['tags']);
        self::assertSame('Task management', $op['description']);
        self::assertSame([
            ['name' => 'q', 'in' => 'query', 'schema' => ['type' => ['string', 'null'], 'description' => 'Full-text query', 'default' => null], 'description' => 'Full-text query'],
            ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20]],
            ['name' => 'archived', 'in' => 'query', 'schema' => ['type' => 'boolean', 'deprecated' => true, 'default' => false], 'deprecated' => true],
        ], $op['parameters']);
        self::assertSame([200, 400, 429], array_keys(self::node($op, 'responses')));
        self::assertSame(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/TaskView']], self::node($op, 'responses', '200', 'content', 'application/json', 'schema'));
        self::assertSame(['$ref' => '#/components/schemas/Problem'], self::node($op, 'responses', '429', 'content', 'application/problem+json', 'schema'));
        self::assertArrayNotHasKey('security', $op);
    }

    public function testBodyDtoSchemaCarriesValidationRulesEnumsNestedAndNullableTypes(): void
    {
        $doc = $this->document();
        $op = self::node($doc, 'paths', '/tasks', 'post');

        self::assertSame('taskCreate', $op['operationId']);
        self::assertSame(['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CreateTask']]]], $op['requestBody']);
        self::assertSame([201, 400, 401, 403], array_keys(self::node($op, 'responses')));
        self::assertSame('Created', self::node($op, 'responses', '201')['description']);
        self::assertSame(['$ref' => '#/components/schemas/TaskView'], self::node($op, 'responses', '201', 'content', 'application/json', 'schema'));
        self::assertSame([['bearer' => []]], $op['security']);

        $schema = self::node($doc, 'components', 'schemas', 'CreateTask');
        self::assertSame(['title', 'owner'], $schema['required']);
        $schema = self::node($schema, 'properties');
        self::assertSame(['type' => 'string', 'minLength' => 3, 'maxLength' => 80], $schema['title']);
        self::assertSame(['type' => 'string', 'format' => 'email'], $schema['owner']);
        self::assertSame(['type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'default' => 1], $schema['weight']);
        self::assertSame(['type' => 'integer', 'enum' => [1, 2], 'default' => 1], $schema['priority']);
        self::assertSame(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag'], 'maxItems' => 10, 'default' => []], $schema['tags']);
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string'], 'default' => []], $schema['labels']);
        self::assertSame(['type' => ['string', 'null'], 'pattern' => '^[a-z-]+$', 'default' => null], $schema['slug']);
        self::assertSame(['type' => 'string', 'enum' => ['open', 'closed'], 'default' => 'open'], $schema['state']);
        self::assertSame(['oneOf' => [['type' => 'string'], ['type' => 'integer'], ['type' => 'null']], 'default' => null], $schema['external']);
        self::assertSame(['type' => ['string', 'null'], 'format' => 'date-time', 'default' => null], $schema['due']);

        $tag = self::node($doc, 'components', 'schemas', 'Tag');
        self::assertSame(['label'], $tag['required']);
        self::assertSame(['type' => 'string', 'maxLength' => 20, 'description' => 'Tag label', 'examples' => ['urgent']], self::node($tag, 'properties')['label']);
    }

    public function testPathParametersResponsesAttributesAndDeprecation(): void
    {
        $doc = $this->document();
        $show = self::node($doc, 'paths', '/tasks/{id}', 'get');
        self::assertSame([['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer', 'pattern' => '^\d+$']]], $show['parameters']);
        self::assertSame([200, 400, 404], array_keys(self::node($show, 'responses')));
        self::assertSame(['description' => 'The task', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/TaskView']]]], self::node($show, 'responses', '200'));
        self::assertSame(['description' => 'No such task'], self::node($show, 'responses', '404'));

        $delete = self::node($doc, 'paths', '/tasks/{id}', 'delete');
        self::assertSame('removeTask', $delete['operationId']);
        self::assertTrue($delete['deprecated']);
        self::assertSame([204, 400, 401], array_keys(self::node($delete, 'responses')));
        self::assertSame([
            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            ['name' => 'force', 'in' => 'query', 'schema' => ['type' => 'boolean', 'default' => false]],
        ], $delete['parameters']);

        $export = self::node($doc, 'paths', '/tasks/export', 'get');
        self::assertSame(['text/html' => ['schema' => ['type' => 'string']]], self::node($export, 'responses', '200', 'content'));
        self::assertArrayNotHasKey('parameters', $export);
    }

    public function testProblemDetailsCanBeLeftOut(): void
    {
        $doc = $this->document(new OpenApiConfig(problemDetails: false));
        self::assertSame([200], array_keys(self::node($doc, 'paths', '/tasks', 'get', 'responses')));
        self::assertArrayNotHasKey('Problem', self::node($doc, 'components', 'schemas'));
        self::assertSame(['title' => 'API', 'version' => '1.0.0'], $doc['info']);
        self::assertArrayNotHasKey('servers', $doc);
    }

    public function testMiddlewareServesSpecAndDocsAndCachesTheDocument(): void
    {
        $definitions = new Definitions();
        $definitions->instance(OpenApiConfig::class, new OpenApiConfig('Tasks API', specPath: '/spec.json', docsPath: '/reference'));
        $client = TestClient::forKernel(Kernel::build($definitions, [TaskController::class], [OpenApiMiddleware::class]));

        $client->get('/spec.json')->assertOk()->assertHeader('Content-Type', 'application/json')->assertJsonPath('info.title', 'Tasks API');
        $page = $client->get('/reference')->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8')->body();
        self::assertStringContainsString('data-url="/spec.json"', $page);
        self::assertStringContainsString('<title>Tasks API</title>', $page);
        $client->get('/tasks/export')->assertOk();
        $client->post('/spec.json')->assertStatus(404);

        $middleware = new OpenApiMiddleware(new Generator(Router::fromControllers(TaskController::class)), Kernel::build(new Definitions(), [])->container->get(Responder::class));
        $document = $middleware->document();
        self::assertStringContainsString('"openapi": "3.1.0"', $document);
        self::assertSame($document, $middleware->document(), 'generated once per process');
    }

    public function testDocTypesResolveClassesRelativeToTheNamespace(): void
    {
        $method = new \ReflectionMethod(TaskController::class, 'list');
        self::assertSame(\Keel\Tests\OpenApi\Fixtures\TaskView::class, DocTypes::returnItems($method));

        $parameters = new \ReflectionMethod(\Keel\Tests\OpenApi\Fixtures\CreateTask::class, '__construct')->getParameters();
        self::assertSame(\Keel\Tests\OpenApi\Fixtures\Tag::class, DocTypes::paramItems($parameters[4]));
        self::assertSame('string', DocTypes::paramItems($parameters[5]));
        self::assertNull(DocTypes::paramItems($parameters[0]));

        $builder = new SchemaBuilder();
        self::assertSame(['type' => 'array', 'items' => ['type' => 'integer']], $builder->named('array', 'int'));
        self::assertSame([], $builder->named('Nope\Missing'));
        self::assertSame(['type' => 'number'], $builder->named('float'));
    }
}
