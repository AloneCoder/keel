<?php

declare(strict_types=1);

namespace Keel\App;

use Keel\Config\AppConfig;
use Keel\Config\ConfigLoader;
use Keel\Config\DiscoveryConfig;
use Keel\Config\Env;
use Keel\Config\HttpConfig;
use Keel\Config\ServicesConfig;
use Keel\Container\Compiled\AbstractCompiledContainer;
use Keel\Container\Compiler;
use Keel\Container\Container;
use Keel\Container\ContainerInterface;
use Keel\Container\Definitions;
use Keel\Discovery\CommandDiscovery;
use Keel\Discovery\Discoverer;
use Keel\Discovery\ListenerDiscovery;
use Keel\Discovery\RouteDiscovery;
use Keel\Discovery\ServiceDiscovery;
use Keel\Event\EventDispatcher;
use Keel\Event\ListenerProvider;
use Keel\Kernel\Kernel;
use Keel\Routing\Router;
use Keel\Runtime\Runtime;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Boots an application from a project directory using the conventions:
 *   config/*.php   return config objects (AppConfig, HttpConfig, DiscoveryConfig, ServicesConfig, your own)
 *   src/           scanned for controllers, services with container attributes, listeners, commands
 *   var/cache/ENV  discovery.php, routes.php, container.php (used in production, rebuilt by keel:compile)
 *
 * Production with warm caches is the fast path: config objects, the route cache, the compiled
 * container and the listener list from the discovery cache. No reflection, no definitions,
 * no scanner. Everything else (debug, missing caches, a $configure closure) goes through the
 * full assembly, which also writes the caches when not in debug mode.
 */
final class App
{
    public const string COMPILED_NAMESPACE = 'Keel\Generated';

    /**
     * @param array<class-string, object> $configs
     * @param list<class-string>          $controllers
     * @param array<string, class-string> $commands console command name => class
     */
    private function __construct(
        public readonly string $projectDir,
        public readonly Env $env,
        public readonly AppConfig $config,
        public readonly array $configs,
        public readonly ContainerInterface $container,
        public readonly Router $router,
        public readonly Kernel $kernel,
        public readonly array $controllers,
        private readonly array $commands,
    ) {}

    /**
     * @param (\Closure(Definitions, Env): void)|null $configure last-minute definitions for debug mode and tests;
     *                                                           forces the full assembly
     */
    public static function boot(string $projectDir, ?Env $env = null, ?\Closure $configure = null): self
    {
        $projectDir = rtrim($projectDir, '/');
        $env ??= self::envFor($projectDir);
        [$config, $configs] = self::configs($projectDir, $env);
        $cacheDir = $config->cacheDir();

        if (!$config->debug && $configure === null && self::cachesExist($cacheDir)) {
            return self::fromCaches($projectDir, $env, $config, $configs, $cacheDir);
        }

        return self::assemble($projectDir, $env, $config, $configs, $cacheDir, $configure);
    }

    public function run(): int
    {
        return Runtime::run($this->kernel);
    }

    /**
     * Rebuilds every cache file regardless of the environment. Returns the written paths.
     *
     * @return list<string>
     */
    public function compile(): array
    {
        $cacheDir = $this->config->cacheDir();
        @unlink($cacheDir . '/discovery.php');

        [$definitions, $discoverer] = self::definitions($this->projectDir, $this->env, $this->configs, $cacheDir, true, null);
        $controllers = $discoverer->get(RouteDiscovery::class)->controllers();
        $router = Router::fromControllers(...$controllers);
        $router->write($cacheDir . '/routes.php');
        $definitions->instance(Router::class, $router);
        new Compiler($definitions)->write($cacheDir . '/container.php', self::compiledClassName($this->config), self::COMPILED_NAMESPACE);

        return [$cacheDir . '/discovery.php', $cacheDir . '/routes.php', $cacheDir . '/container.php'];
    }

    /**
     * @return list<string> removed paths
     */
    public function clearCache(): array
    {
        $removed = [];
        foreach (['discovery.php', 'routes.php', 'container.php'] as $file) {
            $path = $this->config->cacheDir() . '/' . $file;
            if (is_file($path) && unlink($path)) {
                $removed[] = $path;
            }
        }

        return $removed;
    }

    /** @return array<string, class-string> discovered console commands, name => class */
    public function commands(): array
    {
        return $this->commands;
    }

    /**
     * The class name embeds project and environment, so several apps can live in one process.
     */
    public static function compiledClassName(AppConfig $config): string
    {
        return 'KeelCompiledContainer_' . substr(md5($config->projectDir . '|' . $config->env), 0, 12);
    }

    private static function cachesExist(string $cacheDir): bool
    {
        return is_file($cacheDir . '/discovery.php') && is_file($cacheDir . '/routes.php') && is_file($cacheDir . '/container.php');
    }

    /**
     * @param array<class-string, object> $configs
     */
    private static function fromCaches(string $projectDir, Env $env, AppConfig $config, array $configs, string $cacheDir): self
    {
        $discovery = self::discoveryCache($cacheDir . '/discovery.php');
        $router = Router::fromCache($cacheDir . '/routes.php');
        $instances = [Env::class => $env] + $configs + [Router::class => $router];
        $container = self::compiledContainer($instances, $cacheDir, $config);

        self::registerListeners($container->get(ListenerProvider::class), $discovery[ListenerDiscovery::class] ?? []);
        $http = $configs[HttpConfig::class];
        $kernel = new Kernel($container, $router, $http instanceof HttpConfig ? $http->middleware : [], false);

        return new self(
            $projectDir,
            $env,
            $config,
            $configs,
            $container,
            $router,
            $kernel,
            self::classList($discovery[RouteDiscovery::class] ?? []),
            self::commandMap($discovery[CommandDiscovery::class] ?? []),
        );
    }

    /**
     * @param array<class-string, object>              $configs
     * @param (\Closure(Definitions, Env): void)|null $configure
     */
    private static function assemble(
        string $projectDir,
        Env $env,
        AppConfig $config,
        array $configs,
        string $cacheDir,
        ?\Closure $configure,
    ): self {
        [$definitions, $discoverer] = self::definitions($projectDir, $env, $configs, $cacheDir, $config->debug, $configure);
        $controllers = $discoverer->get(RouteDiscovery::class)->controllers();

        $router = self::router($controllers, $cacheDir, $config->debug);
        $definitions->instance(Router::class, $router);

        if ($config->debug) {
            $container = new Container($definitions);
        } else {
            $cache = $cacheDir . '/container.php';
            if (!is_file($cache)) {
                new Compiler($definitions)->write($cache, self::compiledClassName($config), self::COMPILED_NAMESPACE);
            }
            $container = self::compiledContainer($definitions->instances(), $cacheDir, $config);
        }

        $discoverer->get(ListenerDiscovery::class)->register($container->get(ListenerProvider::class));
        $http = $configs[HttpConfig::class];
        $kernel = new Kernel($container, $router, $http instanceof HttpConfig ? $http->middleware : [], $config->debug);

        return new self(
            $projectDir,
            $env,
            $config,
            $configs,
            $container,
            $router,
            $kernel,
            $controllers,
            $discoverer->get(CommandDiscovery::class)->commands(),
        );
    }

    /**
     * Runs discovery and builds the definitions: env, configs, providers, discovered services,
     * events, kernel defaults, then the $configure closure.
     *
     * @param array<class-string, object>              $configs
     * @param (\Closure(Definitions, Env): void)|null $configure
     *
     * @return array{0: Definitions, 1: Discoverer}
     */
    private static function definitions(
        string $projectDir,
        Env $env,
        array $configs,
        string $cacheDir,
        bool $checkFreshness,
        ?\Closure $configure,
    ): array {
        $discoveryConfig = $configs[DiscoveryConfig::class];
        $discoverer = self::discoverer(
            $discoveryConfig instanceof DiscoveryConfig ? $discoveryConfig : DiscoveryConfig::forProject($projectDir),
            $cacheDir,
            $checkFreshness,
        );
        $discoverer->run();

        $definitions = new Definitions();
        $definitions->instance(Env::class, $env);
        foreach ($configs as $class => $object) {
            $definitions->instance($class, $object);
        }
        $services = $configs[ServicesConfig::class];
        if ($services instanceof ServicesConfig) {
            foreach ($services->providers as $provider) {
                $definitions->load(new $provider());
            }
        }
        $discoverer->get(ServiceDiscovery::class)->register($definitions);
        if ($definitions->definition(ListenerProvider::class) === null) {
            $definitions->set(ListenerProvider::class)->alias(ListenerProviderInterface::class);
        }
        if ($definitions->definition(EventDispatcher::class) === null) {
            $definitions->set(EventDispatcher::class)->alias(EventDispatcherInterface::class);
        }
        Kernel::registerDefaults($definitions);
        $configure?->__invoke($definitions, $env);

        return [$definitions, $discoverer];
    }

    /**
     * @return array{0: AppConfig, 1: array<class-string, object>}
     */
    private static function configs(string $projectDir, Env $env): array
    {
        $configs = new ConfigLoader()->load($projectDir . '/config', $env, $projectDir);
        $config = self::configOf($configs, AppConfig::class) ?? AppConfig::fromEnv($projectDir, $env);
        $configs += [
            AppConfig::class => $config,
            HttpConfig::class => self::configOf($configs, HttpConfig::class) ?? new HttpConfig(),
            DiscoveryConfig::class => self::configOf($configs, DiscoveryConfig::class) ?? DiscoveryConfig::forProject($projectDir),
            ServicesConfig::class => self::configOf($configs, ServicesConfig::class) ?? new ServicesConfig(),
        ];

        return [$config, $configs];
    }

    private static function envFor(string $projectDir): Env
    {
        $env = Env::fromGlobals();
        $file = $projectDir . '/.env';

        return is_file($file) ? Env::fromFile($file, $env) : $env;
    }

    /**
     * @template T of object
     *
     * @param array<class-string, object> $configs
     * @param class-string<T>             $class
     *
     * @return T|null
     */
    private static function configOf(array $configs, string $class): ?object
    {
        $object = $configs[$class] ?? null;

        return $object instanceof $class ? $object : null;
    }

    private static function discoverer(DiscoveryConfig $config, string $cacheDir, bool $checkFreshness): Discoverer
    {
        return new Discoverer($config->paths, $cacheDir . '/discovery.php', checkFreshness: $checkFreshness)
            ->add(new RouteDiscovery())
            ->add(new ServiceDiscovery())
            ->add(new ListenerDiscovery())
            ->add(new CommandDiscovery());
    }

    /**
     * @param list<class-string> $controllers
     */
    private static function router(array $controllers, string $cacheDir, bool $debug): Router
    {
        $cache = $cacheDir . '/routes.php';
        if ($debug) {
            return Router::fromControllers(...$controllers);
        }
        if (is_file($cache)) {
            return Router::fromCache($cache);
        }

        $router = Router::fromControllers(...$controllers);
        $router->write($cache);

        return $router;
    }

    /**
     * @param array<string, object> $instances
     */
    private static function compiledContainer(array $instances, string $cacheDir, AppConfig $config): AbstractCompiledContainer
    {
        $cache = $cacheDir . '/container.php';
        $className = self::compiledClassName($config);
        if (!class_exists(self::COMPILED_NAMESPACE . '\\' . $className, false)) {
            require $cache;
        }

        $fqcn = self::compiledClass($className, $cache);

        return new $fqcn($instances);
    }

    /**
     * @return class-string<AbstractCompiledContainer>
     */
    private static function compiledClass(string $className, string $cache): string
    {
        $fqcn = self::COMPILED_NAMESPACE . '\\' . $className;
        if (!is_a($fqcn, AbstractCompiledContainer::class, true)) {
            throw new \RuntimeException(sprintf('Compiled container "%s" is not a %s', $cache, AbstractCompiledContainer::class));
        }

        return $fqcn;
    }

    /**
     * Reads the discovery cache without instantiating the discoveries.
     *
     * @return array<string, array<mixed>> discovery class => result
     */
    private static function discoveryCache(string $file): array
    {
        /** @var mixed $data */
        $data = require $file;
        $results = is_array($data) ? ($data['results'] ?? null) : null;
        if (!is_array($results)) {
            throw new \RuntimeException(sprintf('Discovery cache "%s" is malformed; run keel:compile', $file));
        }

        $typed = [];
        foreach ($results as $class => $result) {
            if (is_string($class) && is_array($result)) {
                $typed[$class] = $result;
            }
        }

        return $typed;
    }

    /**
     * @param array<mixed> $tuples class, method, event, priority
     */
    private static function registerListeners(ListenerProvider $provider, array $tuples): void
    {
        foreach ($tuples as $tuple) {
            if (!is_array($tuple) || count($tuple) !== 4) {
                continue;
            }
            [$class, $method, $event, $priority] = array_values($tuple);
            if (is_string($class) && is_string($method) && is_string($event) && is_int($priority)) {
                /** @var class-string $class */
                /** @var class-string $event */
                $provider->add($event, [$class, $method], $priority);
            }
        }
    }

    /**
     * Cached class names are trusted; the classes load when first used.
     *
     * @param array<mixed> $values
     *
     * @return list<class-string>
     */
    private static function classList(array $values): array
    {
        $classes = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                /** @var class-string $value */
                $classes[] = $value;
            }
        }

        return $classes;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, class-string>
     */
    private static function commandMap(array $values): array
    {
        $commands = [];
        foreach ($values as $name => $class) {
            if (is_string($name) && is_string($class)) {
                /** @var class-string $class */
                $commands[$name] = $class;
            }
        }

        return $commands;
    }
}
