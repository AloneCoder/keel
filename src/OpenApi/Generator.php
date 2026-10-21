<?php

declare(strict_types=1);

namespace Keel\OpenApi;

use Keel\Auth\Attribute\Auth;
use Keel\Auth\Authentication;
use Keel\Http\Attribute\FromBody;
use Keel\Http\Attribute\FromQuery;
use Keel\Http\Exception\HttpException;
use Keel\OpenApi\Attribute\Field;
use Keel\OpenApi\Attribute\Hidden;
use Keel\OpenApi\Attribute\Operation;
use Keel\OpenApi\Attribute\Response;
use Keel\RateLimit\Attribute\RateLimit;
use Keel\Routing\Route;
use Keel\Routing\Router;
use Psr\Http\Message\ResponseInterface;

/**
 * Builds an OpenAPI 3.1 document from the routes: path placeholders, #[FromQuery] and
 * #[FromBody] parameters with their DTO schemas and validation rules, #[Status] and return
 * types or #[Response] attributes, #[Auth] security, #[RateLimit] and Problem Details errors.
 */
final class Generator
{
    private const string PLACEHOLDER = '~\{([a-zA-Z_][a-zA-Z0-9_]*)(?::((?:[^{}]|\{(?-1)\})*))?\}~';

    private const string PROBLEM = 'application/problem+json';

    public function __construct(
        private readonly Router $router,
        private readonly OpenApiConfig $config = new OpenApiConfig(),
        private readonly ?Authentication $authentication = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        $schemas = new SchemaBuilder();
        $paths = [];
        $securitySchemes = $this->securitySchemes();
        $usesProblem = false;

        foreach ($this->router->routes() as $route) {
            [$class, $methodName] = $route->handler;
            $controller = new \ReflectionClass($class);
            $method = $controller->getMethod($methodName);
            if ($controller->getAttributes(Hidden::class) !== [] || $method->getAttributes(Hidden::class) !== []) {
                continue;
            }

            $operation = $this->operation($route, $controller, $method, $schemas, array_keys($securitySchemes), $usesProblem);
            $path = (string) preg_replace(self::PLACEHOLDER, '{$1}', $route->path);
            foreach ($route->methods as $httpMethod) {
                $paths[$path][strtolower($httpMethod)] = $operation;
            }
        }

        $components = [];
        $componentSchemas = $schemas->schemas();
        if ($usesProblem) {
            $componentSchemas['Problem'] = self::problemSchema();
            ksort($componentSchemas);
        }
        if ($componentSchemas !== []) {
            $components['schemas'] = $componentSchemas;
        }
        if ($securitySchemes !== []) {
            $components['securitySchemes'] = $securitySchemes;
        }

        $info = ['title' => $this->config->title, 'version' => $this->config->version];
        if ($this->config->description !== null) {
            $info['description'] = $this->config->description;
        }

        $document = ['openapi' => '3.1.0', 'info' => $info];
        if ($this->config->servers !== []) {
            $document['servers'] = $this->config->servers;
        }
        $document['paths'] = (object) $paths;
        if ($components !== []) {
            $document['components'] = $components;
        }

        return $document;
    }

    /**
     * @param \ReflectionClass<object> $controller
     * @param list<string>             $securitySchemes
     *
     * @return array<string, mixed>
     */
    private function operation(
        Route $route,
        \ReflectionClass $controller,
        \ReflectionMethod $method,
        SchemaBuilder $schemas,
        array $securitySchemes,
        bool &$usesProblem,
    ): array {
        $classOp = self::operationAttribute($controller);
        $methodOp = self::operationAttribute($method);

        $tags = $methodOp?->tags ?: ($classOp?->tags ?: [self::defaultTag($controller)]);
        $operation = [
            'operationId' => $methodOp->operationId ?? $route->name ?? lcfirst(substr(self::defaultTag($controller), 0, 1)) . substr(self::defaultTag($controller), 1) . ucfirst($method->getName()),
            'tags' => $tags,
        ];
        $summary = $methodOp?->summary;
        if ($summary !== null) {
            $operation['summary'] = $summary;
        }
        $description = $methodOp->description ?? $classOp->description ?? null;
        if ($description !== null) {
            $operation['description'] = $description;
        }
        if ($methodOp?->deprecated === true || $classOp?->deprecated === true) {
            $operation['deprecated'] = true;
        }

        [$parameters, $requestBody, $hasInput] = $this->inputs($route, $method, $schemas);
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }
        if ($requestBody !== null) {
            $operation['requestBody'] = $requestBody;
        }

        $responses = $this->responses($route, $method, $schemas);
        $auth = $route->option(Auth::KEY);
        if ($this->config->problemDetails) {
            if ($hasInput) {
                $responses[400] = self::problemResponse('Invalid request data', $usesProblem);
            }
            if (is_array($auth)) {
                $responses[401] = self::problemResponse('Authentication required', $usesProblem);
                if (($auth['roles'] ?? []) !== []) {
                    $responses[403] = self::problemResponse('Insufficient role', $usesProblem);
                }
            }
            if ($route->option(RateLimit::KEY) !== null) {
                $responses[429] = self::problemResponse('Rate limit exceeded', $usesProblem);
            }
        }
        ksort($responses);
        $operation['responses'] = array_combine(array_map(strval(...), array_keys($responses)), $responses);

        if (is_array($auth) && $securitySchemes !== []) {
            $operation['security'] = array_map(static fn(string $scheme): array => [$scheme => []], $securitySchemes);
        }

        return $operation;
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>|null, 2: bool}
     */
    private function inputs(Route $route, \ReflectionMethod $method, SchemaBuilder $schemas): array
    {
        $parameters = [];
        $byName = [];
        foreach ($method->getParameters() as $parameter) {
            $byName[$parameter->getName()] = $parameter;
        }

        preg_match_all(self::PLACEHOLDER, $route->path, $matches, \PREG_SET_ORDER);
        foreach ($matches as $match) {
            $name = $match[1];
            $schema = isset($byName[$name]) ? $schemas->forParameter($byName[$name]) : ['type' => 'string'];
            unset($schema['default']);
            if (isset($match[2]) && $match[2] !== '' && !isset($schema['pattern']) && !isset($schema['enum'])) {
                $schema['pattern'] = '^' . $match[2] . '$';
            }
            $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => $schema];
        }

        $requestBody = null;
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $class = $type instanceof \ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;

            if ($parameter->getAttributes(FromQuery::class) !== []) {
                if ($class !== null && class_exists($class)) {
                    foreach (self::constructorParameters($class) as $field) {
                        $parameters[] = self::queryParameter($field, $schemas);
                    }
                } else {
                    $parameters[] = self::queryParameter($parameter, $schemas);
                }
            } elseif ($parameter->getAttributes(FromBody::class) !== []) {
                $schema = $class !== null && class_exists($class) ? $schemas->classSchema($class) : ['type' => 'object'];
                $requestBody = ['required' => true, 'content' => ['application/json' => ['schema' => $schema]]];
            }
        }

        return [$parameters, $requestBody, $parameters !== [] || $requestBody !== null];
    }

    /**
     * @param class-string $class
     *
     * @return list<\ReflectionParameter>
     */
    private static function constructorParameters(string $class): array
    {
        $constructor = new \ReflectionClass($class)->getConstructor();

        return $constructor === null ? [] : $constructor->getParameters();
    }

    /**
     * @return array<string, mixed>
     */
    private static function queryParameter(\ReflectionParameter $parameter, SchemaBuilder $schemas): array
    {
        $schema = $schemas->forParameter($parameter);
        $entry = ['name' => $parameter->getName(), 'in' => 'query', 'schema' => $schema];
        if (!$parameter->isDefaultValueAvailable() && !$parameter->allowsNull()) {
            $entry['required'] = true;
        }
        if (isset($schema['description'])) {
            $entry['description'] = $schema['description'];
        }
        $fields = $parameter->getAttributes(Field::class);
        if ($fields !== [] && $fields[0]->newInstance()->deprecated) {
            $entry['deprecated'] = true;
        }

        return $entry;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function responses(Route $route, \ReflectionMethod $method, SchemaBuilder $schemas): array
    {
        $responses = [];
        foreach ($method->getAttributes(Response::class) as $attribute) {
            $declared = $attribute->newInstance();
            $entry = ['description' => $declared->description !== '' ? $declared->description : HttpException::reasonPhrase($declared->status)];
            if ($declared->type !== null) {
                $entry['content'] = [$declared->contentType => ['schema' => $schemas->named($declared->type, $declared->items)]];
            }
            $responses[$declared->status] = $entry;
        }
        if ($responses !== []) {
            return $responses;
        }

        $type = $method->getReturnType();
        $name = $type instanceof \ReflectionNamedType ? $type->getName() : null;
        $status = $route->status ?? ($name === 'void' || $name === 'null' ? 204 : 200);
        $entry = ['description' => HttpException::reasonPhrase($status)];

        if ($name === 'void' || $name === 'null' || $status === 204) {
            return [$status => $entry];
        }
        if ($name !== null && !$type->isBuiltin() && is_a($name, ResponseInterface::class, true)) {
            return [$status => $entry];
        }
        if ($name === 'string') {
            $entry['content'] = ['text/html' => ['schema' => ['type' => 'string']]];

            return [$status => $entry];
        }

        $schema = $schemas->forType($type, DocTypes::returnItems($method));
        $entry['content'] = ['application/json' => ['schema' => $schema]];

        return [$status => $entry];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function securitySchemes(): array
    {
        $schemes = [];
        foreach ($this->authentication === null ? [] : $this->authentication->authenticators() as $authenticator) {
            $scheme = $authenticator->securityScheme();
            $schemes[$scheme->name] = $scheme->definition;
        }

        return $schemes;
    }

    /**
     * @return array<string, mixed>
     */
    private static function problemResponse(string $description, bool &$usesProblem): array
    {
        $usesProblem = true;

        return ['description' => $description, 'content' => [self::PROBLEM => ['schema' => ['$ref' => '#/components/schemas/Problem']]]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function problemSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'RFC 9457 Problem Details',
            'properties' => [
                'type' => ['type' => 'string', 'format' => 'uri'],
                'title' => ['type' => 'string'],
                'status' => ['type' => 'integer'],
                'detail' => ['type' => 'string'],
                'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['type', 'title', 'status'],
        ];
    }

    /**
     * @param \ReflectionClass<object>|\ReflectionMethod $owner
     */
    private static function operationAttribute(\ReflectionClass|\ReflectionMethod $owner): ?Operation
    {
        $attributes = $owner->getAttributes(Operation::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * @param \ReflectionClass<object> $controller
     */
    private static function defaultTag(\ReflectionClass $controller): string
    {
        $short = $controller->getShortName();

        return str_ends_with($short, 'Controller') && strlen($short) > 10 ? substr($short, 0, -10) : $short;
    }
}
