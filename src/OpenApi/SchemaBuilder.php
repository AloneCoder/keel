<?php

declare(strict_types=1);

namespace Keel\OpenApi;

use Keel\Http\Pagination\Page;
use Keel\OpenApi\Attribute\Field;
use Keel\Validation\Alnum;
use Keel\Validation\Choice;
use Keel\Validation\Count;
use Keel\Validation\Date;
use Keel\Validation\Each;
use Keel\Validation\Email;
use Keel\Validation\Hostname;
use Keel\Validation\Ip;
use Keel\Validation\Length;
use Keel\Validation\NotBlank;
use Keel\Validation\Pattern;
use Keel\Validation\Range;
use Keel\Validation\Rule;
use Keel\Validation\Unique;
use Keel\Validation\Url;
use Keel\Validation\Uuid;

/**
 * JSON Schema (OpenAPI 3.1 dialect) for PHP types. DTO classes become components named after
 * the class; their properties are the constructor parameters, typed from the signature,
 * constrained from Keel\Validation rules and described by #[Field].
 */
final class SchemaBuilder
{
    /** @var array<string, array<string, mixed>> component name => schema */
    private array $schemas = [];

    /** @var array<class-string, string> */
    private array $names = [];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function schemas(): array
    {
        ksort($this->schemas);

        return $this->schemas;
    }

    /**
     * Schema of a type name as #[Response] / #[Field] spell it: a class, "int", "string", "array"...
     *
     * @return array<string, mixed>
     */
    public function named(string $type, ?string $items = null): array
    {
        $scalar = self::scalar($type);
        if ($scalar !== null) {
            return $scalar;
        }
        if ($type === 'array' || $type === 'iterable' || $type === 'list') {
            return ['type' => 'array', 'items' => $items === null ? [] : $this->named($items)];
        }
        if ($type === Page::class) {
            return $this->pageSchema($items);
        }
        if (class_exists($type) || enum_exists($type)) {
            /** @var class-string $type */
            return $this->classSchema($type);
        }

        return [];
    }

    /**
     * Schema of a reflected type, with #[Field] and validation rules of the parameter applied.
     *
     * @return array<string, mixed>
     */
    public function forParameter(\ReflectionParameter $parameter): array
    {
        $fields = $parameter->getAttributes(Field::class);
        $field = $fields === [] ? null : $fields[0]->newInstance();
        $items = $field->items ?? DocTypes::paramItems($parameter);

        $schema = $this->forType($parameter->getType(), $items);
        $schema = self::constrain($schema, $parameter);

        if ($field !== null) {
            if ($field->description !== null) {
                $schema['description'] = $field->description;
            }
            if ($field->format !== null) {
                $schema['format'] = $field->format;
            }
            if ($field->example !== null) {
                $schema['examples'] = [$field->example];
            }
            if ($field->deprecated) {
                $schema['deprecated'] = true;
            }
        }
        if ($parameter->isDefaultValueAvailable()) {
            $default = $parameter->getDefaultValue();
            if ($default instanceof \BackedEnum) {
                $default = $default->value;
            } elseif ($default instanceof \UnitEnum) {
                $default = $default->name;
            }
            if ($default === null || is_scalar($default) || is_array($default)) {
                $schema['default'] = $default;
            }
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    public function forType(?\ReflectionType $type, ?string $items = null): array
    {
        if ($type === null) {
            return [];
        }

        if ($type instanceof \ReflectionUnionType) {
            $members = [];
            foreach ($type->getTypes() as $member) {
                if ($member instanceof \ReflectionNamedType && $member->getName() === 'null') {
                    continue;
                }
                $members[] = $this->forType($member, $items);
            }
            if (count($members) === 1) {
                return $type->allowsNull() ? self::nullable($members[0]) : $members[0];
            }
            if ($type->allowsNull()) {
                $members[] = ['type' => 'null'];
            }

            return ['oneOf' => $members];
        }

        if (!$type instanceof \ReflectionNamedType) {
            return [];
        }

        $name = $type->getName();
        $scalar = self::scalar($name);
        if ($scalar !== null) {
            $schema = $scalar;
        } elseif ($type->isBuiltin() || $name === 'self' || $name === 'static') {
            $schema = match ($name) {
                'null' => ['type' => 'null'],
                'array', 'iterable' => ['type' => 'array', 'items' => $items === null ? [] : $this->named($items)],
                'object' => ['type' => 'object'],
                default => [],
            };
        } elseif ($name === Page::class) {
            $schema = $this->pageSchema($items);
        } else {
            /** @var class-string $name */
            $schema = $this->classSchema($name);
        }

        return $type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? self::nullable($schema) : $schema;
    }

    /**
     * The envelope of Keel\Http\Pagination\Page, inline because the element type differs per
     * route: from "@return Page<Item>" or #[Response(type: Page::class, items: Item::class)].
     *
     * @return array<string, mixed>
     */
    public function pageSchema(?string $items): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => ['type' => 'array', 'items' => $items === null ? [] : $this->named($items)],
                'page' => ['type' => 'integer', 'minimum' => 1],
                'perPage' => ['type' => 'integer', 'minimum' => 1],
                'total' => ['type' => 'integer', 'minimum' => 0],
                'pages' => ['type' => 'integer', 'minimum' => 0],
            ],
            'required' => ['items', 'page', 'perPage', 'total', 'pages'],
        ];
    }

    /**
     * A $ref to the component of a DTO class, an inline enum, or string/date-time for dates.
     *
     * @param class-string $class
     *
     * @return array<string, mixed>
     */
    public function classSchema(string $class): array
    {
        if (enum_exists($class)) {
            return self::enumSchema($class);
        }
        if (is_a($class, \DateTimeInterface::class, true)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }
        if (!class_exists($class)) {
            return [];
        }

        return ['$ref' => '#/components/schemas/' . $this->register($class)];
    }

    /**
     * @param class-string $class
     */
    private function register(string $class): string
    {
        if (isset($this->names[$class])) {
            return $this->names[$class];
        }

        $short = substr($class, (int) strrpos($class, '\\') + 1);
        $name = isset($this->schemas[$short]) ? str_replace('\\', '.', $class) : $short;
        $this->names[$class] = $name;
        $this->schemas[$name] = ['type' => 'object'];

        $properties = [];
        $required = [];
        $constructor = new \ReflectionClass($class)->getConstructor();
        foreach ($constructor === null ? [] : $constructor->getParameters() as $parameter) {
            $properties[$parameter->getName()] = $this->forParameter($parameter);
            if (self::isRequired($parameter)) {
                $required[] = $parameter->getName();
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        $this->schemas[$name] = $schema;

        return $name;
    }

    private static function isRequired(\ReflectionParameter $parameter): bool
    {
        if ($parameter->getAttributes(NotBlank::class) !== []) {
            return true;
        }

        return !$parameter->isDefaultValueAvailable() && !$parameter->allowsNull();
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function constrain(array $schema, \ReflectionParameter $parameter): array
    {
        foreach ($parameter->getAttributes(Rule::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $schema = self::applyRule($schema, $attribute->newInstance());
        }

        return $schema;
    }

    /**
     * What one rule says about a schema; rules without a JSON Schema equivalent (Json, Timezone) say nothing.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function applyRule(array $schema, Rule $rule): array
    {
        return match (true) {
            $rule instanceof Length => array_merge($schema, array_filter(['minLength' => $rule->min, 'maxLength' => $rule->max], static fn(?int $v): bool => $v !== null)),
            $rule instanceof Range => array_merge($schema, array_filter(['minimum' => $rule->min, 'maximum' => $rule->max], static fn(int|float|null $v): bool => $v !== null)),
            $rule instanceof Count => array_merge($schema, array_filter(['minItems' => $rule->min, 'maxItems' => $rule->max], static fn(?int $v): bool => $v !== null)),
            $rule instanceof Pattern => array_merge($schema, ['pattern' => self::pattern($rule->regex)]),
            $rule instanceof Alnum => array_merge($schema, ['pattern' => self::pattern($rule->regex())]),
            $rule instanceof Email => array_merge($schema, ['format' => 'email']),
            $rule instanceof Url => array_merge($schema, ['format' => 'uri']),
            $rule instanceof Uuid => array_merge($schema, ['format' => 'uuid']),
            $rule instanceof Hostname => array_merge($schema, ['format' => 'hostname']),
            $rule instanceof Ip => $rule->version === null ? $schema : array_merge($schema, ['format' => 'ipv' . $rule->version]),
            $rule instanceof Date => array_merge($schema, ['format' => self::dateFormat($rule->format)]),
            $rule instanceof Choice => array_merge($schema, ['enum' => $rule->choices]),
            $rule instanceof Unique => array_merge($schema, ['uniqueItems' => true]),
            $rule instanceof Each => self::constrainItems($schema, $rule),
            $rule instanceof NotBlank => ($schema['type'] ?? null) === 'string' ? $schema + ['minLength' => 1] : $schema,
            default => $schema,
        };
    }

    /**
     * The item rules of #[Each] go to "items" (merged over the element type when one is declared).
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function constrainItems(array $schema, Each $each): array
    {
        $items = [];
        foreach (is_array($schema['items'] ?? null) ? $schema['items'] : [] as $key => $value) {
            if (is_string($key)) {
                $items[$key] = $value;
            }
        }
        foreach ($each->rules as $rule) {
            $items = self::applyRule($items, $rule);
        }

        return $items === [] ? $schema : array_merge($schema, ['items' => $items]);
    }

    /**
     * "Y-m-d" is a JSON Schema date, RFC 3339 formats a date-time; anything else has no name and keeps "date".
     */
    private static function dateFormat(string $format): string
    {
        return in_array($format, [\DATE_ATOM, \DATE_RFC3339, \DATE_RFC3339_EXTENDED, 'Y-m-d\\TH:i:sP', 'Y-m-d\\TH:i:s.uP', 'Y-m-d H:i:s'], true) ? 'date-time' : 'date';
    }

    /**
     * "/^[a-z]+$/" becomes "^[a-z]+$" (ECMA regex has no delimiters); other delimiters or flags stay as is.
     */
    private static function pattern(string $regex): string
    {
        return preg_match('~^/(.*)/$~s', $regex, $m) === 1 ? $m[1] : $regex;
    }

    /**
     * @param class-string<\UnitEnum> $enum
     *
     * @return array<string, mixed>
     */
    private static function enumSchema(string $enum): array
    {
        if (is_subclass_of($enum, \BackedEnum::class)) {
            $backing = (string) new \ReflectionEnum($enum)->getBackingType();

            return ['type' => $backing === 'int' ? 'integer' : 'string', 'enum' => array_map(static fn(\BackedEnum $c): int|string => $c->value, $enum::cases())];
        }

        return ['type' => 'string', 'enum' => array_map(static fn(\UnitEnum $c): string => $c->name, $enum::cases())];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function scalar(string $name): ?array
    {
        return match ($name) {
            'int', 'integer' => ['type' => 'integer'],
            'float', 'double', 'number' => ['type' => 'number'],
            'string' => ['type' => 'string'],
            'bool', 'boolean' => ['type' => 'boolean'],
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function nullable(array $schema): array
    {
        if (isset($schema['$ref']) || isset($schema['oneOf'])) {
            return ['oneOf' => [$schema, ['type' => 'null']]];
        }
        if (isset($schema['type']) && is_string($schema['type'])) {
            $schema['type'] = [$schema['type'], 'null'];
        }
        // "enum" is exhaustive in JSON Schema: null must be listed to validate.
        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array(null, $schema['enum'], true)) {
            $schema['enum'][] = null;
        }

        return $schema;
    }
}
