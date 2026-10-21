<?php

declare(strict_types=1);

namespace Keel\Container;

use Keel\Container\Compiled\AbstractCompiledContainer;
use Keel\Container\Exception\ContainerException;
use Keel\Container\Reflection\ArgumentPlan;
use Keel\Container\Reflection\ArgumentSource;
use Keel\Container\Reflection\ClassAttributes;
use Keel\Container\Reflection\Instantiator;
use Keel\Container\Reflection\Planner;

/**
 * Generates a PHP class with one method per service, so production never touches reflection
 * for known services. Every definition and every class reachable from it through
 * constructor types is compiled; classes requested at runtime that were not reachable
 * fall back to reflection (see AbstractCompiledContainer).
 *
 * Not compilable: closure factories, factories bound to an object, argument values and
 * parameters that are not scalars, arrays, null or enums. Register such things as instances
 * or switch to a static factory method.
 *
 * The ids that were autowired here (not registered) are listed in the AUTOWIRED constant, so a
 * compiled container built with autowire: false ignores them like the runtime container does.
 */
final class Compiler
{
    private const string HASH_LINE = "    public const string CONTENT_HASH = '%s';";

    /**
     * Words PHP refuses as a class name (keywords, magic constants and reserved type names),
     * lower-cased: the identifier regex accepts them, the generated file would not parse.
     * Namespace segments may be keywords since PHP 8.0, so only the class name is checked.
     */
    private const array RESERVED_CLASS_NAMES = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const',
        'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare',
        'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'eval', 'exit', 'extends', 'final',
        'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include',
        'include_once', 'instanceof', 'insteadof', 'interface', 'isset', 'list', 'match', 'namespace',
        'new', 'or', 'print', 'private', 'protected', 'public', 'readonly', 'require', 'require_once',
        'return', 'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use', 'var', 'while', 'xor',
        'yield', '__halt_compiler', '__class__', '__dir__', '__file__', '__function__', '__line__',
        '__method__', '__namespace__', '__trait__', '__property__', 'int', 'float', 'bool', 'string',
        'true', 'false', 'null', 'void', 'iterable', 'object', 'mixed', 'never', 'self', 'parent',
    ];

    private readonly Planner $planner;

    /** @var array<string, Definition> */
    private array $compiled = [];

    /** @var list<string> */
    private array $queue = [];

    /** @var array<string, true> ids in $queue */
    private array $queued = [];

    public function __construct(private readonly Definitions $definitions, ?Planner $planner = null)
    {
        $this->planner = $planner ?? new Planner();
    }

    /**
     * Writes the generated class atomically (temporary file + rename).
     */
    public function write(string $path, string $className = 'KeelCompiledContainer', ?string $namespace = null): void
    {
        $source = $this->compile($className, $namespace);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new ContainerException(sprintf('Cannot create directory "%s"', $directory));
        }

        $temporary = $directory . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temporary, $source) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new ContainerException(sprintf('Cannot write compiled container to "%s"', $path));
        }
    }

    /**
     * @throws ContainerException when $className or $namespace is not a valid PHP identifier
     */
    public function compile(string $className = 'KeelCompiledContainer', ?string $namespace = null): string
    {
        self::assertIdentifier($className, 'class name');
        if (in_array(strtolower($className), self::RESERVED_CLASS_NAMES, true)) {
            throw new ContainerException(sprintf('Invalid class name for the compiled container: "%s" is reserved by PHP', $className));
        }
        if ($namespace !== null) {
            foreach (explode('\\', $namespace) as $segment) {
                self::assertIdentifier($segment, 'namespace');
            }
        }

        $this->compiled = [];
        $this->queue = array_keys($this->definitions->definitions());
        $this->queued = array_fill_keys($this->queue, true);

        $methods = [];
        $cases = [];
        $autowired = [];
        $index = 0;
        while (($id = array_shift($this->queue)) !== null) {
            unset($this->queued[$id]);
            if (isset($this->compiled[$id])) {
                continue;
            }

            $definition = $this->definitions->definition($id);
            if ($definition === null) {
                $definition = $this->autowiredDefinition($id);
                if ($definition === null) {
                    continue;
                }
                $autowired[$id] = true;
            }

            $this->compiled[$id] = $definition;
            $method = 'service' . $index++;
            $methods[] = $this->methodSource($method, $definition);
            $cases[] = sprintf('            %s => $this->%s(),', self::string($id), $method);
        }

        $transient = [];
        foreach ($this->compiled as $id => $definition) {
            if (!$definition->shared) {
                $transient[$id] = true;
            }
        }

        $aliases = [];
        foreach ($this->definitions->aliases() as $alias => $target) {
            $aliases[$alias] = $this->definitions->resolveAlias($target);
        }

        $serviceIds = array_fill_keys(array_keys($this->compiled), true);

        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            '// Generated by ' . self::class . '. Do not edit.',
            '',
        ];
        if ($namespace !== null) {
            $lines[] = 'namespace ' . $namespace . ';';
            $lines[] = '';
        }

        $lines[] = sprintf('final class %s extends \\%s', $className, AbstractCompiledContainer::class);
        $lines[] = '{';
        $lines[] = '    protected const array SERVICE_IDS = ' . self::export($serviceIds, 1) . ';';
        $lines[] = '    protected const array AUTOWIRED = ' . self::export($autowired, 1) . ';';
        $lines[] = '    protected const array TRANSIENT = ' . self::export($transient, 1) . ';';
        $lines[] = '    protected const array ALIASES = ' . self::export($aliases, 1) . ';';
        $lines[] = '    protected const array TAGS = ' . self::export($this->definitions->tags(), 1) . ';';
        $lines[] = '    protected const array PARAMETERS = ' . self::export($this->definitions->parameters(), 1) . ';';
        $lines[] = '    protected const array INSTANCE_IDS = '
            . self::export(array_keys($this->definitions->instances()), 1) . ';';
        $hashLine = count($lines);
        $lines[] = '';
        $lines[] = '    protected function create(string $id): mixed';
        $lines[] = '    {';
        $lines[] = '        return match ($id) {';
        foreach ($cases as $case) {
            $lines[] = $case;
        }
        $lines[] = '            default => throw new \LogicException("Unknown compiled service: {$id}"),';
        $lines[] = '        };';
        $lines[] = '    }';
        foreach ($methods as $method) {
            $lines[] = '';
            $lines[] = $method;
        }
        $lines[] = '}';
        $lines[] = '';

        // The hash covers everything but its own line, so contentHash() recovers it from the file.
        array_splice($lines, $hashLine, 0, [sprintf(self::HASH_LINE, md5(implode("\n", $lines)))]);

        return implode("\n", $lines);
    }

    /**
     * Hash of a generated file, as stored in its CONTENT_HASH constant. App compares the two to
     * notice that a cache was rewritten after its class was loaded in a long-lived process.
     */
    public static function contentHash(string $source): string
    {
        return md5(preg_replace('/^' . sprintf(preg_quote(self::HASH_LINE, '/'), '[0-9a-f]{32}') . '\n/m', '', $source, 1) ?? $source);
    }

    private function methodSource(string $method, Definition $definition): string
    {
        [$returnType, $body] = $definition->factory !== null
            ? $this->factoryBody($definition)
            : $this->constructorBody($definition);

        return implode("\n", [
            // The id is arbitrary text: keep it from closing the comment or breaking the line.
            '    /** ' . str_replace(['*/', "\r", "\n"], ['*\/', ' ', ' '], $definition->id) . ' */',
            sprintf('    private function %s(): %s', $method, $returnType),
            '    {',
            '        ' . $body,
            '    }',
        ]);
    }

    /**
     * @return array{0: string, 1: string} return type and method body
     */
    private function constructorBody(Definition $definition): array
    {
        $class = $this->planner->classOf($definition->class);
        $constructor = $class->getConstructor();
        $plans = $constructor === null
            ? []
            : $this->planner->parameters($constructor, $definition->arguments, $definition->class . '::__construct');
        $arguments = $this->argumentsSource($plans);
        $fqcn = '\\' . $class->getName();

        if (!$definition->lazy) {
            return [$fqcn, sprintf('return new %s(%s);', $fqcn, $arguments)];
        }

        Instantiator::assertLazyCapable($class);
        if ($constructor !== null && !$constructor->isPublic()) {
            throw new ContainerException(sprintf(
                'Lazy service "%s" needs a public constructor to be compiled',
                $definition->id,
            ));
        }

        $initializer = $constructor === null
            ? sprintf('static function (%s $object): void {}', $fqcn)
            : sprintf('function (%s $object): void { $object->__construct(%s); }', $fqcn, $arguments);

        return [
            $fqcn,
            sprintf('return new \ReflectionClass(%s::class)->newLazyGhost(%s);', $fqcn, $initializer),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function factoryBody(Definition $definition): array
    {
        $factory = $definition->factory;
        if ($factory === null) {
            throw new \LogicException('factoryBody() called for a definition without factory');
        }

        if ($factory instanceof \Closure) {
            throw new ContainerException(sprintf(
                'Closure factory of "%s" cannot be compiled; use "Class::method" or [Class::class, \'method\']',
                $definition->id,
            ));
        }

        if (is_string($factory) && !str_contains($factory, '::')) {
            if (!function_exists($factory)) {
                throw new ContainerException(sprintf('Factory "%s" of "%s" is not callable', $factory, $definition->id));
            }
            $function = new \ReflectionFunction($factory);
            $call = sprintf('\\%s(%%s)', ltrim($factory, '\\'));
        } else {
            if (is_string($factory)) {
                [$class, $methodName] = explode('::', $factory, 2);
            } else {
                [$class, $methodName] = $factory;
            }

            if (!is_string($class)) {
                throw new ContainerException(sprintf(
                    'Factory of "%s" is bound to an object and cannot be compiled; use a class name',
                    $definition->id,
                ));
            }

            if (!method_exists($class, $methodName)) {
                throw new ContainerException(sprintf(
                    'Factory %s::%s of "%s" does not exist',
                    $class,
                    $methodName,
                    $definition->id,
                ));
            }

            $function = new \ReflectionMethod($class, $methodName);
            if (!$function->isPublic()) {
                throw new ContainerException(sprintf('Factory %s::%s of "%s" must be public', $class, $methodName, $definition->id));
            }
            $call = $function->isStatic()
                ? sprintf('\\%s::%s(%%s)', ltrim($class, '\\'), $methodName)
                : sprintf('$this->get(%s)->%s(%%s)', self::string($class), $methodName);
            if (!$function->isStatic()) {
                $this->reference($class);
            }
        }

        $plans = $this->planner->parameters($function, $definition->arguments, 'factory of ' . $definition->id);
        $expression = sprintf($call, $this->argumentsSource($plans));

        if (!$definition->lazy) {
            return ['mixed', 'return ' . $expression . ';'];
        }

        if (!class_exists($definition->class)) {
            throw new ContainerException(sprintf(
                'Lazy factory for "%s" needs a class: pass it to Definitions::set($id, $class)',
                $definition->id,
            ));
        }

        $class = new \ReflectionClass($definition->class);
        Instantiator::assertLazyCapable($class);
        $fqcn = '\\' . $class->getName();

        return [
            $fqcn,
            sprintf('return new \ReflectionClass(%s::class)->newLazyProxy(fn (): %s => %s);', $fqcn, $fqcn, $expression),
        ];
    }

    /**
     * @param list<ArgumentPlan> $plans
     */
    private function argumentsSource(array $plans): string
    {
        $parts = [];
        foreach ($plans as $plan) {
            $parts[] = match ($plan->source) {
                ArgumentSource::Service => $this->serviceSource($plan->id()),
                ArgumentSource::OptionalService => sprintf(
                    '($this->has(%s) ? %s : %s)',
                    self::string($plan->id()),
                    $this->serviceSource($plan->id()),
                    self::export($plan->default, 2),
                ),
                ArgumentSource::Tagged => $this->taggedSource($plan->id()),
                ArgumentSource::Value => self::export($plan->value, 2),
            };
        }

        return implode(', ', $parts);
    }

    private function serviceSource(string $id): string
    {
        $this->reference($id);

        return sprintf('$this->get(%s)', self::string($id));
    }

    private function taggedSource(string $tag): string
    {
        foreach ($this->definitions->tagged($tag) as $id) {
            $this->reference($id);
        }

        return sprintf('$this->tagged(%s)', self::string($tag));
    }

    /**
     * Schedules a referenced id for compilation when it is a class we can autowire.
     */
    private function reference(string $id): void
    {
        $id = $this->definitions->resolveAlias($id);
        if (isset($this->compiled[$id]) || isset($this->queued[$id])) {
            return;
        }

        if ($this->definitions->definition($id) !== null || $this->autowiredDefinition($id) !== null) {
            $this->queue[] = $id;
            $this->queued[$id] = true;
        }
    }

    private function autowiredDefinition(string $id): ?Definition
    {
        if ($this->definitions->hasParameter($id) || isset($this->definitions->instances()[$id])) {
            return null;
        }

        if (!class_exists($id)) {
            return null;
        }

        $class = new \ReflectionClass($id);
        if (!$class->isInstantiable()) {
            return null;
        }

        $definition = new Definition($id);
        ClassAttributes::apply($class, $definition);

        return $definition;
    }

    private static function string(string $value): string
    {
        return var_export($value, true);
    }

    private static function assertIdentifier(string $value, string $what): void
    {
        if (preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $value) !== 1) {
            throw new ContainerException(sprintf('Invalid %s for the compiled container: "%s"', $what, $value));
        }
    }

    /**
     * Exports scalars, null, enums and arrays as compact PHP source.
     */
    private static function export(mixed $value, int $depth): string
    {
        if ($value === null || is_scalar($value)) {
            return var_export($value, true);
        }

        if ($value instanceof \UnitEnum) {
            return '\\' . $value::class . '::' . $value->name;
        }

        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            $indent = str_repeat('    ', $depth + 1);
            $isList = array_is_list($value);
            $lines = ['['];
            foreach ($value as $key => $item) {
                $lines[] = $indent
                    . ($isList ? '' : var_export($key, true) . ' => ')
                    . self::export($item, $depth + 1) . ',';
            }
            $lines[] = str_repeat('    ', $depth) . ']';

            return implode("\n", $lines);
        }

        throw new ContainerException(sprintf(
            'Value of type %s cannot be compiled; register it as an instance or build it in a factory',
            get_debug_type($value),
        ));
    }
}
