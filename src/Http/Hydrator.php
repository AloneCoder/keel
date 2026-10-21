<?php

declare(strict_types=1);

namespace Keel\Http;

use Keel\Http\Exception\BadRequestException;

/**
 * Builds DTOs from request data by calling the constructor with named, type-cast values.
 * Supports scalars, nullable types, defaults, arrays, backed and unit enums and nested DTOs.
 * Every problem is collected and reported at once as a 400 with per-field errors.
 */
final class Hydrator
{
    /**
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $data
     *
     * @return T
     */
    public function hydrate(string $class, array $data, string $path = ''): object
    {
        $errors = [];
        $object = $this->build($class, $data, $path, $errors);
        if ($errors !== []) {
            throw new BadRequestException('Invalid request data', [], $errors);
        }

        return $object;
    }

    /**
     * Casts one value to the type of a parameter. Throws 400 on mismatch.
     */
    public function cast(mixed $value, \ReflectionParameter $parameter, string $path): mixed
    {
        $errors = [];
        $result = $this->castValue($value, $parameter->getType(), $path, $errors);
        if ($errors !== []) {
            throw new BadRequestException('Invalid request data', [], $errors);
        }

        return $result;
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $data
     * @param list<string>         $errors
     *
     * @return T
     */
    private function build(string $class, array $data, string $path, array &$errors): object
    {
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            $field = $path === '' ? $name : $path . '.' . $name;

            if (array_key_exists($name, $data)) {
                $arguments[] = $this->castValue($data[$name], $parameter->getType(), $field, $errors);
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } elseif ($parameter->allowsNull()) {
                $arguments[] = null;
            } else {
                $errors[] = sprintf('%s: field is required', $field);
                $arguments[] = null;
            }
        }

        if ($errors !== []) {
            // Constructing with placeholders would raise TypeErrors; the caller reports the errors.
            return $reflection->newInstanceWithoutConstructor();
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /**
     * @param list<string> $errors
     */
    private function castValue(mixed $value, ?\ReflectionType $type, string $path, array &$errors): mixed
    {
        if ($type === null) {
            return $value;
        }

        if ($value === null) {
            if ($type->allowsNull()) {
                return null;
            }
            $errors[] = sprintf('%s: must not be null', $path);

            return null;
        }

        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                $attempt = [];
                $result = $this->castValue($value, $member, $path, $attempt);
                if ($attempt === []) {
                    return $result;
                }
            }
            $errors[] = sprintf('%s: does not match any of %s', $path, $type);

            return null;
        }

        if (!$type instanceof \ReflectionNamedType) {
            $errors[] = sprintf('%s: unsupported parameter type %s', $path, $type);

            return null;
        }

        $name = $type->getName();

        return match ($name) {
            'mixed' => $value,
            'int' => $this->toInt($value, $path, $errors),
            'float' => $this->toFloat($value, $path, $errors),
            'bool' => $this->toBool($value, $path, $errors),
            'string' => $this->toString($value, $path, $errors),
            'array', 'iterable' => $this->toArray($value, $path, $errors),
            default => $this->toObject($value, $name, $path, $errors),
        };
    }

    /**
     * @param list<string> $errors
     */
    private function toInt(mixed $value, string $path, array &$errors): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value && is_finite($value)) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        $errors[] = sprintf('%s: must be an integer', $path);

        return null;
    }

    /**
     * @param list<string> $errors
     */
    private function toFloat(mixed $value, string $path, array &$errors): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        $errors[] = sprintf('%s: must be a number', $path);

        return null;
    }

    /**
     * @param list<string> $errors
     */
    private function toBool(mixed $value, string $path, array &$errors): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false' || $value === '') {
            return false;
        }

        $errors[] = sprintf('%s: must be a boolean', $path);

        return null;
    }

    /**
     * @param list<string> $errors
     */
    private function toString(mixed $value, string $path, array &$errors): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $errors[] = sprintf('%s: must be a string', $path);

        return null;
    }

    /**
     * @param list<string> $errors
     *
     * @return array<mixed>|null
     */
    private function toArray(mixed $value, string $path, array &$errors): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        $errors[] = sprintf('%s: must be an array', $path);

        return null;
    }

    /**
     * @param list<string> $errors
     */
    private function toObject(mixed $value, string $class, string $path, array &$errors): ?object
    {
        if (enum_exists($class)) {
            return $this->toEnum($value, $class, $path, $errors);
        }

        if (!class_exists($class)) {
            $errors[] = sprintf('%s: unsupported type %s', $path, $class);

            return null;
        }

        if (is_object($value) && $value instanceof $class) {
            return $value;
        }

        if (!is_array($value)) {
            $errors[] = sprintf('%s: must be an object', $path);

            return null;
        }

        /** @var array<string, mixed> $value */
        return $this->build($class, $value, $path, $errors);
    }

    /**
     * @param class-string<\UnitEnum> $enum
     * @param list<string>            $errors
     */
    private function toEnum(mixed $value, string $enum, string $path, array &$errors): ?\UnitEnum
    {
        if ($value instanceof $enum) {
            return $value;
        }

        if (is_subclass_of($enum, \BackedEnum::class)) {
            $backing = (string) new \ReflectionEnum($enum)->getBackingType();
            $candidate = $backing === 'int' ? (is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null)
                : (is_string($value) ? $value : null);
            $case = $candidate === null ? null : $enum::tryFrom($candidate);
            if ($case !== null) {
                return $case;
            }
        } elseif (is_string($value)) {
            foreach ($enum::cases() as $case) {
                if ($case->name === $value) {
                    return $case;
                }
            }
        }

        $allowed = array_map(
            static fn(\UnitEnum $case): string => $case instanceof \BackedEnum ? (string) $case->value : $case->name,
            $enum::cases(),
        );
        $errors[] = sprintf('%s: must be one of %s', $path, implode(', ', $allowed));

        return null;
    }
}
