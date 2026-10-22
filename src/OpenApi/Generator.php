<?php

declare(strict_types=1);

namespace Keel\OpenApi;

use Keel\Auth\Attribute\Auth;
use Keel\Auth\Attribute\Can;
use Keel\Auth\Attribute\Signed;
use Keel\Auth\Authentication;
use Keel\Auth\UrlSigner;
use Keel\Http\Attribute\BodyLimit;
use Keel\Http\Attribute\Deprecated;
use Keel\Http\Attribute\FromBody;
use Keel\Http\Attribute\FromFile;
use Keel\Http\Attribute\FromQuery;
use Keel\Http\Exception\HttpException;
use Keel\Idempotency\Attribute\Idempotent;
use Keel\Idempotency\IdempotencyConfig;
use Keel\OpenApi\Attribute\Field;
use Keel\OpenApi\Attribute\Hidden;
use Keel\OpenApi\Attribute\Operation;
use Keel\OpenApi\Attribute\Response;
use Keel\RateLimit\Attribute\RateLimit;
use Keel\Routing\PathParser;
use Keel\Routing\Redirector;
use Keel\Routing\Route;
use Keel\Routing\Router;
use Psr\Http\Message\ResponseInterface;

/**
 * Builds an OpenAPI 3.1 document from the routes: path placeholders, #[FromQuery] and
 * #[FromBody] parameters with their DTO schemas and validation rules, #[Status] and return
 * types or #[Response] attributes, #[Auth] security, #[RateLimit] and Problem Details errors,
 * #[Deprecated] (deprecated: true, x-sunset), #[Idempotent] (the Idempotency-Key header, 409
 * and 422), #[BodyLimit] (413, 415) and #[Signed] (the signature query parameters, 403).
 */
final class Generator
{
    private const string PROBLEM = 'application/problem+json';

    public function __construct(
        private readonly Router $router,
        private readonly OpenApiConfig $config = new OpenApiConfig(),
        private readonly ?Authentication $authentication = null,
        private readonly ?IdempotencyConfig $idempotency = null,
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
        $operationIds = [];

        foreach ($this->router->routes() as $route) {
            [$class, $methodName] = $route->handler;
            if ($class === Redirector::class) {
                continue; // redirect routes carry no operation worth documenting
            }
            $controller = new \ReflectionClass($class);
            $method = $controller->getMethod($methodName);
            if ($controller->getAttributes(Hidden::class) !== [] || $method->getAttributes(Hidden::class) !== []) {
                continue;
            }

            // A path with optional segments is several OpenAPI paths: one operation per variant,
            // each listing only the placeholders that variant has.
            foreach (new PathParser()->expand($route->path) as $variant) {
                $operation = $this->operation($route, $variant, $controller, $method, $schemas, array_keys($securitySchemes), $usesProblem);
                $path = (string) preg_replace(Route::PLACEHOLDER, '{$1}', $variant);
                $baseId = $operation['operationId'];
                $several = count($route->methods) > 1;
                foreach ($route->methods as $httpMethod) {
                    $verb = strtolower($httpMethod);
                    $operation['operationId'] = self::uniqueOperationId($baseId . ($several ? ucfirst($verb) : ''), $operationIds);
                    $paths[$path][$verb] = $operation;
                }
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
     * Operation ids must be unique in a document: a second use gets a numeric suffix.
     *
     * @param array<string, true> $used
     */
    private static function uniqueOperationId(string $id, array &$used): string
    {
        $candidate = $id;
        for ($n = 2; isset($used[$candidate]); $n++) {
            $candidate = $id . $n;
        }
        $used[$candidate] = true;

        return $candidate;
    }

    /**
     * @param \ReflectionClass<object> $controller
     * @param list<string>             $securitySchemes
     *
     * @return array<string, mixed>&array{operationId: string}
     */
    private function operation(
        Route $route,
        string $path,
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
            'operationId' => $methodOp->operationId ?? $route->name ?? lcfirst(self::defaultTag($controller)) . ucfirst($method->getName()),
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
        $deprecated = $route->option(Deprecated::KEY);
        if ($methodOp?->deprecated === true || $classOp?->deprecated === true || is_array($deprecated)) {
            $operation['deprecated'] = true;
        }
        if (is_array($deprecated) && is_int($deprecated['sunset'] ?? null)) {
            $operation['x-sunset'] = gmdate('Y-m-d\TH:i:s\Z', $deprecated['sunset']);
        }

        [$parameters, $requestBody, $hasInput] = $this->inputs($path, $method, $schemas);
        $idempotent = $route->option(Idempotent::KEY);
        if (is_array($idempotent)) {
            $parameters[] = [
                'name' => $this->idempotency->header ?? 'Idempotency-Key',
                'in' => 'header',
                'required' => ($idempotent['required'] ?? false) === true,
                'description' => 'Unique key of this operation; a retry with the same key returns the first response',
                'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
            ];
        }
        $signed = $route->option(Signed::KEY) === true;
        if ($signed) {
            $parameters[] = [
                'name' => UrlSigner::EXPIRES,
                'in' => 'query',
                'required' => false,
                'description' => 'Expiry of the signed link (unix time), part of the signed URL',
                'schema' => ['type' => 'integer'],
            ];
            $parameters[] = [
                'name' => UrlSigner::SIGNATURE,
                'in' => 'query',
                'required' => true,
                'description' => 'Signature of the link, issued by the application',
                'schema' => ['type' => 'string'],
            ];
        }
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }
        if ($requestBody !== null) {
            $operation['requestBody'] = $requestBody;
        }

        $responses = $this->responses($route, $method, $schemas);
        $auth = $route->option(Auth::KEY);
        $can = $route->option(Can::KEY);
        if ($this->config->problemDetails) {
            if ($hasInput) {
                $responses[400] = self::problemResponse('Invalid request data', $usesProblem);
            }
            if (is_array($auth) || is_array($can)) {
                $responses[401] = self::problemResponse('Authentication required', $usesProblem);
                if ((is_array($auth) && ($auth['roles'] ?? []) !== []) || is_array($can)) {
                    $responses[403] = self::problemResponse(is_array($can) ? 'Not allowed' : 'Insufficient role', $usesProblem);
                }
            }
            if ($signed) {
                $responses[403] ??= self::problemResponse('Missing, invalid or expired URL signature', $usesProblem);
            }
            if ($route->option(RateLimit::KEY) !== null) {
                $responses[429] = self::problemResponse('Rate limit exceeded', $usesProblem);
            }
            if (is_array($idempotent)) {
                $responses[409] = self::problemResponse('A request with this key is still being processed', $usesProblem);
                $responses[422] = self::problemResponse('The key was used for a different request', $usesProblem);
            }
            $bodyLimit = $route->option(BodyLimit::KEY);
            if (is_array($bodyLimit)) {
                if (is_int($bodyLimit['maxBytes'] ?? null) && $bodyLimit['maxBytes'] > 0) {
                    $responses[413] = self::problemResponse(sprintf('Request body larger than %d bytes', $bodyLimit['maxBytes']), $usesProblem);
                }
                if (is_array($bodyLimit['contentTypes'] ?? null) && $bodyLimit['contentTypes'] !== []) {
                    $responses[415] = self::problemResponse('Unsupported request body media type', $usesProblem);
                }
            }
        }
        ksort($responses);
        $operation['responses'] = array_combine(array_map(strval(...), array_keys($responses)), $responses);

        if ((is_array($auth) || is_array($can)) && $securitySchemes !== []) {
            $operation['security'] = array_map(static fn(string $scheme): array => [$scheme => []], $securitySchemes);
        }

        return $operation;
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>|null, 2: bool}
     */
    private function inputs(string $path, \ReflectionMethod $method, SchemaBuilder $schemas): array
    {
        $parameters = [];
        $byName = [];
        foreach ($method->getParameters() as $parameter) {
            $byName[$parameter->getName()] = $parameter;
        }

        preg_match_all(Route::PLACEHOLDER, $path, $matches, \PREG_SET_ORDER);
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
        $files = [];
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $class = $type instanceof \ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;

            if ($parameter->getAttributes(FromFile::class) !== []) {
                $name = $parameter->getAttributes(FromFile::class)[0]->newInstance()->name ?? $parameter->getName();
                $binary = ['type' => 'string', 'format' => 'binary'];
                $files[$name] = [
                    'schema' => $class === null && $type instanceof \ReflectionNamedType && in_array($type->getName(), ['array', 'iterable'], true) ? ['type' => 'array', 'items' => $binary] : $binary,
                    'required' => !$parameter->isDefaultValueAvailable() && !$parameter->allowsNull(),
                ];
            } elseif ($parameter->getAttributes(FromQuery::class) !== []) {
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

        if ($files !== []) {
            // Uploads make the body multipart; a JSON DTO beside them is documented as its fields.
            $properties = [];
            $required = [];
            $existing = $requestBody['content']['application/json']['schema'] ?? null;
            if (is_array($existing) && isset($existing['$ref']) && is_string($existing['$ref'])) {
                $component = $schemas->schemas()[substr($existing['$ref'], strlen('#/components/schemas/'))] ?? null;
                if (is_array($component)) {
                    $properties = is_array($component['properties'] ?? null) ? $component['properties'] : [];
                    $required = is_array($component['required'] ?? null) ? $component['required'] : [];
                }
            }
            foreach ($files as $name => $file) {
                $properties[$name] = $file['schema'];
                if ($file['required']) {
                    $required[] = $name;
                }
            }
            $schema = ['type' => 'object', 'properties' => $properties];
            if ($required !== []) {
                $schema['required'] = array_values(array_unique(array_filter($required, is_string(...))));
            }
            $requestBody = ['required' => true, 'content' => ['multipart/form-data' => ['schema' => $schema]]];
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
     * Declared #[Response]s merged over the success response derived from #[Status] and the
     * return type; the derived one is dropped once a 2xx is declared.
     *
     * @return array<int, array<string, mixed>>
     */
    private function responses(Route $route, \ReflectionMethod $method, SchemaBuilder $schemas): array
    {
        $declared = [];
        foreach ($method->getAttributes(Response::class) as $attribute) {
            $response = $attribute->newInstance();
            $entry = ['description' => $response->description !== '' ? $response->description : HttpException::reasonPhrase($response->status)];
            if ($response->type !== null) {
                $entry['content'] = [$response->contentType => ['schema' => $schemas->named($response->type, $response->items)]];
            }
            $declared[$response->status] = $entry;
        }
        foreach (array_keys($declared) as $status) {
            if ($status >= 200 && $status < 300) {
                return $declared;
            }
        }

        return $declared + $this->derivedResponse($route, $method, $schemas);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function derivedResponse(Route $route, \ReflectionMethod $method, SchemaBuilder $schemas): array
    {
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
