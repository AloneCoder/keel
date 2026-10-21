<?php

declare(strict_types=1);

namespace Keel\App;

use Keel\Config\AppConfig;
use Keel\Config\ConfigLoader;
use Keel\Config\Env;
use Keel\Config\HttpConfig;
use Keel\Container\Compiled\AbstractCompiledContainer;
use Keel\Container\ContainerInterface;
use Keel\Container\Definitions;
use Keel\Discovery\CommandDiscovery;
use Keel\Discovery\RouteDiscovery;
use Keel\Kernel\Kernel;
use Keel\Routing\Router;
use Keel\Runtime\Runtime;

/**
 * A booted application. Conventions of the project directory:
 *   config/*.php   return config objects (AppConfig, HttpConfig, DiscoveryConfig, ServicesConfig, your own)
 *   src/           scanned for controllers, services with container attributes, listeners, commands
 *   var/cache/ENV  discovery.php, routes.php, container.php (used in production, rebuilt by keel:compile)
 *
 * Production with warm caches is the fast path and lives in this class: config objects, the
 * route cache and the compiled container, nothing else. Debug mode, missing caches and the
 * $configure closure go through AppBuilder, which also writes the caches when not in debug mode.
 */
final class App
{
    public const string COMPILED_NAMESPACE = 'Keel\Generated';

    /**
     * @internal use App::boot()
     *
     * @param array<class-string, object>          $configs
     * @param array<string, array<mixed>>|null     $discovery discovery results by discovery class; null = read the cache on demand
     */
    public function __construct(
        public readonly string $projectDir,
        public readonly Env $env,
        public readonly AppConfig $config,
        public readonly array $configs,
        public readonly ContainerInterface $container,
        public readonly Router $router,
        public readonly Kernel $kernel,
        private ?array $discovery,
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

        return AppBuilder::assemble($projectDir, $env, $config, $configs, $cacheDir, $configure);
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
        return AppBuilder::compile($this);
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

    /** @return list<class-string> discovered controllers */
    public function controllers(): array
    {
        $classes = [];
        foreach ($this->discovery()[RouteDiscovery::class] ?? [] as $value) {
            if (is_string($value)) {
                /** @var class-string $value */
                $classes[] = $value;
            }
        }

        return $classes;
    }

    /** @return array<string, class-string> discovered console commands, name => class */
    public function commands(): array
    {
        $commands = [];
        foreach ($this->discovery()[CommandDiscovery::class] ?? [] as $name => $class) {
            if (is_string($name) && is_string($class)) {
                /** @var class-string $class */
                $commands[$name] = $class;
            }
        }

        return $commands;
    }

    /**
     * The class name embeds project and environment, so several apps can live in one process.
     */
    public static function compiledClassName(AppConfig $config): string
    {
        return 'KeelCompiledContainer_' . substr(md5($config->projectDir . '|' . $config->env), 0, 12);
    }

    /**
     * @internal shared with AppBuilder
     *
     * @param array<string, object> $instances
     */
    public static function compiledContainer(array $instances, string $cacheDir, AppConfig $config): AbstractCompiledContainer
    {
        $cache = $cacheDir . '/container.php';
        $className = self::compiledClassName($config);
        $fqcn = self::COMPILED_NAMESPACE . '\\' . $className;
        if (!class_exists($fqcn, false)) {
            require $cache;
        }
        if (!is_a($fqcn, AbstractCompiledContainer::class, true)) {
            throw new \RuntimeException(sprintf('Compiled container "%s" is not a %s', $cache, AbstractCompiledContainer::class));
        }

        return new $fqcn($instances);
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
        $router = Router::fromCache($cacheDir . '/routes.php');
        $container = self::compiledContainer([Env::class => $env] + $configs + [Router::class => $router], $cacheDir, $config);
        $http = $configs[HttpConfig::class];
        $kernel = new Kernel($container, $router, $http instanceof HttpConfig ? $http->middleware : [], false);

        return new self($projectDir, $env, $config, $configs, $container, $router, $kernel, null);
    }

    /**
     * Loads config/*.php and fills in AppConfig and HttpConfig defaults. DiscoveryConfig and
     * ServicesConfig are build-time settings; AppBuilder defaults them without registering anything.
     *
     * @return array{0: AppConfig, 1: array<class-string, object>}
     */
    private static function configs(string $projectDir, Env $env): array
    {
        $configs = new ConfigLoader()->load($projectDir . '/config', $env, $projectDir);
        $config = $configs[AppConfig::class] ?? null;
        if (!$config instanceof AppConfig) {
            $config = AppConfig::fromEnv($projectDir, $env);
            $configs[AppConfig::class] = $config;
        }
        if (!($configs[HttpConfig::class] ?? null) instanceof HttpConfig) {
            $configs[HttpConfig::class] = new HttpConfig();
        }

        return [$config, $configs];
    }

    private static function envFor(string $projectDir): Env
    {
        $env = Env::fromGlobals();
        $file = $projectDir . '/.env';

        return is_file($file) ? Env::fromFile($file, $env) : $env;
    }

    /**
     * @return array<string, array<mixed>> discovery class => result, from the cache when booted from caches
     */
    private function discovery(): array
    {
        if ($this->discovery === null) {
            $file = $this->config->cacheDir() . '/discovery.php';
            /** @var mixed $data */
            $data = is_file($file) ? require $file : null;
            $results = is_array($data) ? ($data['results'] ?? null) : null;
            $this->discovery = [];
            foreach (is_array($results) ? $results : [] as $class => $result) {
                if (is_string($class) && is_array($result)) {
                    $this->discovery[$class] = $result;
                }
            }
        }

        return $this->discovery;
    }
}
