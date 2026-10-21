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
 * In debug mode nothing is cached except discovery (validated against file mtimes).
 */
final class App
{
    public const string COMPILED_NAMESPACE = 'Keel\Generated';

    /**
     * @param array<class-string, object> $configs
     * @param list<class-string>          $controllers
     */
    private function __construct(
        public readonly string $projectDir,
        public readonly Env $env,
        public readonly AppConfig $config,
        public readonly array $configs,
        public readonly Definitions $definitions,
        public readonly ContainerInterface $container,
        public readonly Router $router,
        public readonly Kernel $kernel,
        public readonly Discoverer $discoverer,
        public readonly array $controllers,
    ) {}

    /**
     * @param (\Closure(Definitions, Env): void)|null $configure last-minute definitions, mostly for tests
     */
    public static function boot(string $projectDir, ?Env $env = null, ?\Closure $configure = null): self
    {
        $projectDir = rtrim($projectDir, '/');
        $env ??= self::envFor($projectDir);

        $configs = new ConfigLoader()->load($projectDir . '/config', $env, $projectDir);
        $config = self::configOf($configs, AppConfig::class) ?? AppConfig::fromEnv($projectDir, $env);
        $http = self::configOf($configs, HttpConfig::class) ?? new HttpConfig();
        $discoveryConfig = self::configOf($configs, DiscoveryConfig::class) ?? DiscoveryConfig::forProject($projectDir);
        $services = self::configOf($configs, ServicesConfig::class) ?? new ServicesConfig();
        $configs += [
            AppConfig::class => $config,
            HttpConfig::class => $http,
            DiscoveryConfig::class => $discoveryConfig,
            ServicesConfig::class => $services,
        ];
        $cacheDir = $config->cacheDir();

        $discoverer = self::discoverer($discoveryConfig, $cacheDir, $config->debug);
        $discoverer->run();
        $controllers = $discoverer->get(RouteDiscovery::class)->controllers();

        $definitions = new Definitions();
        $definitions->instance(Env::class, $env);
        foreach ($configs as $class => $object) {
            $definitions->instance($class, $object);
        }
        foreach ($services->providers as $provider) {
            $definitions->load(new $provider());
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

        $router = self::router($controllers, $cacheDir, $config->debug);
        $definitions->instance(Router::class, $router);

        $container = self::container($definitions, $cacheDir, $config);
        $discoverer->get(ListenerDiscovery::class)->register($container->get(ListenerProvider::class));

        $kernel = new Kernel($container, $router, $http->middleware, $config->debug);

        return new self($projectDir, $env, $config, $configs, $definitions, $container, $router, $kernel, $discoverer, $controllers);
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

        $this->discoverer->clearCache();
        $discoverer = self::discoverer($this->configs[DiscoveryConfig::class] instanceof DiscoveryConfig
            ? $this->configs[DiscoveryConfig::class]
            : DiscoveryConfig::forProject($this->projectDir), $cacheDir, true);
        $discoverer->run();

        Router::fromControllers(...$discoverer->get(RouteDiscovery::class)->controllers())->write($cacheDir . '/routes.php');
        new Compiler($this->definitions)->write($cacheDir . '/container.php', self::compiledClassName($this->config), self::COMPILED_NAMESPACE);

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
        return $this->discoverer->get(CommandDiscovery::class)->commands();
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

    private static function discoverer(DiscoveryConfig $config, string $cacheDir, bool $debug): Discoverer
    {
        return new Discoverer($config->paths, $cacheDir . '/discovery.php', checkFreshness: $debug)
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

    private static function container(Definitions $definitions, string $cacheDir, AppConfig $config): ContainerInterface
    {
        if ($config->debug) {
            return new Container($definitions);
        }

        $cache = $cacheDir . '/container.php';
        $className = self::compiledClassName($config);
        if (!class_exists(self::COMPILED_NAMESPACE . '\\' . $className, false)) {
            if (!is_file($cache)) {
                new Compiler($definitions)->write($cache, $className, self::COMPILED_NAMESPACE);
            }
            require $cache;
        }

        $fqcn = self::compiledClass($className, $cache);

        return new $fqcn($definitions->instances());
    }

    /**
     * The class name embeds project and environment, so several apps can live in one process.
     */
    public static function compiledClassName(AppConfig $config): string
    {
        return 'KeelCompiledContainer_' . substr(md5($config->projectDir . '|' . $config->env), 0, 12);
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
}
