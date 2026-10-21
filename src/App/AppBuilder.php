<?php

declare(strict_types=1);

namespace Keel\App;

use Keel\Config\AppConfig;
use Keel\Config\DiscoveryConfig;
use Keel\Config\Env;
use Keel\Config\HttpConfig;
use Keel\Config\ServicesConfig;
use Keel\Container\Compiler;
use Keel\Container\Container;
use Keel\Container\Definitions;
use Keel\Container\Exception\ContainerException;
use Keel\Discovery\CommandDiscovery;
use Keel\Discovery\Discoverer;
use Keel\Discovery\ListenerDiscovery;
use Keel\Discovery\RouteDiscovery;
use Keel\Discovery\ServiceDiscovery;
use Keel\Event\EventDispatcher;
use Keel\Event\ListenerProvider;
use Keel\Kernel\Kernel;
use Keel\Log\StreamLogger;
use Keel\Routing\Router;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * The build side of App: discovery, definitions, cache writing. Loaded in debug mode, when a
 * cache is missing and by keel:compile; a production boot with warm caches never touches it.
 *
 * @internal Build side of App; not part of the public API.
 */
final class AppBuilder
{
    /**
     * @param array<class-string, object>              $configs
     * @param (\Closure(Definitions, Env): void)|null $configure
     */
    public static function assemble(
        string $projectDir,
        Env $env,
        AppConfig $config,
        array $configs,
        string $cacheDir,
        ?\Closure $configure,
    ): App {
        [$definitions, $discoverer] = self::definitions($projectDir, $env, $configs, $cacheDir, $config->debug, $configure);
        $controllers = $discoverer->get(RouteDiscovery::class)->controllers();

        $router = self::router($controllers, $cacheDir, $config->debug);
        $definitions->instance(Router::class, $router);

        if ($config->debug) {
            $container = new Container($definitions);
        } else {
            $cache = $cacheDir . '/container.php';
            if (!is_file($cache)) {
                self::assertInstancesSurviveCachedBoot($definitions, $configs);
                new Compiler($definitions)->write($cache, App::compiledClassName($config), App::COMPILED_NAMESPACE);
            }
            $container = App::compiledContainer($definitions->instances(), $cacheDir, $config);
        }

        $http = $configs[HttpConfig::class];
        $kernel = new Kernel($container, $router, $http instanceof HttpConfig ? $http->middleware : [], $config->debug);

        return new App($projectDir, $env, $config, $configs, $container, $router, $kernel, [
            RouteDiscovery::class => $controllers,
            CommandDiscovery::class => $discoverer->get(CommandDiscovery::class)->commands(),
        ]);
    }

    /**
     * Rebuilds every cache file regardless of the environment. Returns the written paths.
     *
     * @return list<string>
     */
    public static function compile(App $app): array
    {
        $cacheDir = $app->config->cacheDir();
        @unlink($cacheDir . '/discovery.php');

        [$definitions, $discoverer] = self::definitions($app->projectDir, $app->env, $app->configs, $cacheDir, true, null);
        $router = Router::fromControllers(...$discoverer->get(RouteDiscovery::class)->controllers());
        $router->write($cacheDir . '/routes.php');
        $definitions->instance(Router::class, $router);
        self::assertInstancesSurviveCachedBoot($definitions, $app->configs);
        new Compiler($definitions)->write($cacheDir . '/container.php', App::compiledClassName($app->config), App::COMPILED_NAMESPACE);

        return [$cacheDir . '/discovery.php', $cacheDir . '/routes.php', $cacheDir . '/container.php'];
    }

    /**
     * The definitions the container of this app is (or would be) compiled from. For keel:debug:container.
     */
    public static function definitionsFor(App $app): Definitions
    {
        [$definitions] = self::definitions($app->projectDir, $app->env, $app->configs, $app->config->cacheDir(), $app->config->debug, null);
        $definitions->instance(Router::class, $app->router);

        return $definitions;
    }

    /**
     * Runs discovery and builds the definitions: env, configs, providers, discovered services,
     * events with the discovered listeners, kernel defaults, the built-in logger unless the
     * application registered a LoggerInterface, then the $configure closure.
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
        $discoveryConfig = $configs[DiscoveryConfig::class] ?? null;
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
        $services = $configs[ServicesConfig::class] ?? null;
        if ($services instanceof ServicesConfig) {
            foreach ($services->providers as $provider) {
                $definitions->load(new $provider());
            }
        }
        $discoverer->get(ServiceDiscovery::class)->register($definitions);

        // Listeners are an argument of the provider, so a production boot registers nothing.
        $provider = $definitions->definition(ListenerProvider::class) ?? $definitions->set(ListenerProvider::class);
        $provider->argument('listeners', $discoverer->get(ListenerDiscovery::class)->result());
        if (self::unbound($definitions, ListenerProviderInterface::class)) {
            $provider->alias(ListenerProviderInterface::class);
        }
        if ($definitions->definition(EventDispatcher::class) === null) {
            $dispatcher = $definitions->set(EventDispatcher::class);
            if (self::unbound($definitions, EventDispatcherInterface::class)) {
                $dispatcher->alias(EventDispatcherInterface::class);
            }
        }
        Kernel::registerDefaults($definitions);
        if ($definitions->definition(StreamLogger::class) === null && self::unbound($definitions, LoggerInterface::class)) {
            $definitions->set(StreamLogger::class)->alias(LoggerInterface::class);
        }
        $configure?->__invoke($definitions, $env);

        return [$definitions, $discoverer];
    }

    /**
     * True when the application bound nothing to $id: no definition, alias or instance. Only
     * then does a framework default take the id; a definition registered under the id itself
     * must not be shadowed by a default alias.
     */
    private static function unbound(Definitions $definitions, string $id): bool
    {
        return $definitions->definition($id) === null
            && !isset($definitions->aliases()[$id])
            && !isset($definitions->instances()[$id]);
    }

    /**
     * A production boot with warm caches (App::fromCaches) re-supplies only Env, the config
     * objects and Router to the compiled container; any other Definitions::instance() would make
     * every boot after the first fail, so refuse to compile it.
     *
     * @param array<class-string, object> $configs
     */
    private static function assertInstancesSurviveCachedBoot(Definitions $definitions, array $configs): void
    {
        $known = [Env::class => true, Router::class => true] + array_fill_keys(array_keys($configs), true);
        $extra = array_diff_key($definitions->instances(), $known);
        if ($extra === []) {
            return;
        }

        throw new ContainerException(sprintf(
            'Instances registered for "%s" cannot be restored on a cached boot: a production boot re-supplies only Env, the config objects and Router. '
            . 'Build the object in a static factory instead ($definitions->set($id, Foo::class)->factory(FooFactory::class . \'::create\')) or return it from a config/*.php file',
            implode('", "', array_keys($extra)),
        ));
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
}
