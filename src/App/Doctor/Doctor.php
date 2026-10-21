<?php

declare(strict_types=1);

namespace Keel\App\Doctor;

use Composer\InstalledVersions;
use Keel\App\App;
use Keel\App\AppBuilder;
use Keel\Cache\CacheConfig;
use Keel\Config\DiscoveryConfig;
use Keel\Container\Compiler;
use Keel\Container\Diagnostics;
use Keel\Container\Exception\ContainerException;
use Keel\Database\DbalConfig;
use Keel\Database\PdoConfig;
use Keel\Health\HealthCheck;
use Keel\Health\HealthStatus;
use Keel\Runtime\FpmRuntime;
use Keel\Runtime\FrankenPhpRuntime;
use Keel\Runtime\MemoryGuard;
use Keel\Runtime\RoadRunnerRuntime;
use Keel\Runtime\Runtime;
use Keel\Session\SessionConfig;
use Spiral\RoadRunner\Http\PSR7Worker;

/**
 * The checks behind keel:doctor: PHP and extensions, configuration, cache files and
 * permissions, the runtime the process would pick, optional packages, the container (a dry
 * run of every service, see Diagnostics, plus a compilation in memory), routes, storage
 * directories and the registered health checks.
 */
final class Doctor
{
    private const array OPTIONAL_PACKAGES = [
        'spiral/roadrunner-http' => 'RoadRunner runtime',
        'doctrine/dbal' => 'DbalProvider',
        'twig/twig' => 'TwigProvider',
        'latte/latte' => 'LatteProvider',
        'monolog/monolog' => 'PSR-3 logger',
        'open-telemetry/sdk' => 'TracingMiddleware',
    ];

    /**
     * @param array<string, mixed>|null $server defaults to $_SERVER
     */
    public function __construct(private readonly App $app, private readonly ?array $server = null) {}

    /**
     * @return list<Finding>
     */
    public function diagnose(bool $healthChecks = true): array
    {
        return [
            ...$this->php(),
            ...$this->extensions(),
            ...$this->configuration(),
            ...$this->caches(),
            ...$this->runtime(),
            ...$this->packages(),
            ...$this->container(),
            ...$this->routes(),
            ...$this->storage(),
            ...($healthChecks ? $this->health() : []),
        ];
    }

    /**
     * @param list<Finding> $findings
     */
    public static function healthy(array $findings): bool
    {
        foreach ($findings as $finding) {
            if ($finding->severity === Severity::Failure) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<Finding>
     */
    private function php(): array
    {
        $findings = [Finding::ok('php', sprintf('PHP %s (%s)', \PHP_VERSION, \PHP_SAPI))];

        if (extension_loaded('Zend OPcache')) {
            $findings[] = Finding::ok('php', 'OPcache loaded');
        } elseif (!$this->app->config->debug) {
            $findings[] = Finding::warning('php', 'OPcache is not loaded: enable it for production, with opcache.preload pointing at the file keel:preload writes');
        }

        $limit = (string) ini_get('memory_limit');
        $findings[] = Finding::ok('php', sprintf('memory_limit %s', $limit === '' ? 'unset' : $limit));

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function extensions(): array
    {
        $findings = [];

        if (extension_loaded('apcu')) {
            $cli = \PHP_SAPI === 'cli' && !(function_exists('apcu_enabled') && apcu_enabled());
            $findings[] = Finding::ok('extensions', 'apcu loaded' . ($cli ? ' (apc.enable_cli is off: counters, cache and metrics use process memory in this CLI, shared memory under the server)' : ''));
        } else {
            $findings[] = Finding::warning('extensions', 'apcu is not loaded: rate-limit counters, the cache and metrics fall back to per-process memory, which workers do not share');
        }

        $findings[] = extension_loaded('curl')
            ? Finding::ok('extensions', 'curl loaded')
            : Finding::warning('extensions', 'curl is not loaded: CurlClient is unavailable');

        if ((isset($this->app->configs[PdoConfig::class]) || isset($this->app->configs[DbalConfig::class])) && !extension_loaded('pdo')) {
            $findings[] = Finding::failure('extensions', 'a database is configured but the pdo extension is not loaded');
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function configuration(): array
    {
        $config = $this->app->config;
        $findings = [Finding::ok('config', sprintf('APP_ENV=%s, debug %s', $config->env, $config->debug ? 'on' : 'off'))];
        if ($config->env === 'prod' && $config->debug) {
            $findings[] = Finding::warning('config', 'debug mode in prod: error pages expose exception details and caches are not used');
        }

        $configDir = $this->app->projectDir . '/config';
        if (is_dir($configDir)) {
            $files = glob($configDir . '/*.php');
            $findings[] = Finding::ok('config', sprintf('config/ with %d file(s)', $files === false ? 0 : count($files)));
        } else {
            $findings[] = Finding::warning('config', 'no config/ directory: every config object takes its defaults');
        }

        $findings[] = Finding::ok('config', is_file($this->app->projectDir . '/.env') ? '.env file loaded' : 'no .env file (environment variables only)');

        $discovery = $this->app->configs[DiscoveryConfig::class] ?? null;
        $paths = $discovery instanceof DiscoveryConfig ? $discovery->paths : [$this->app->projectDir . '/src'];
        foreach ($paths as $path) {
            $findings[] = is_dir($path)
                ? Finding::ok('config', 'discovery path ' . $path)
                : Finding::failure('config', sprintf('discovery path %s does not exist: no controllers, commands or listeners are found there', $path));
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function caches(): array
    {
        $dir = $this->app->config->cacheDir();
        $findings = [];

        if (is_dir($dir)) {
            $findings[] = is_writable($dir)
                ? Finding::ok('cache', sprintf('%s is writable', $dir))
                : Finding::failure('cache', sprintf('%s is not writable: keel:compile and debug discovery cannot write there', $dir));
        } else {
            $parent = self::existingParent($dir);
            $findings[] = $parent !== null && is_writable($parent)
                ? Finding::ok('cache', sprintf('%s does not exist yet and can be created', $dir))
                : Finding::failure('cache', sprintf('%s does not exist and cannot be created (%s is not writable)', $dir, $parent ?? dirname($dir)));
        }

        if ($this->app->config->debug) {
            $findings[] = Finding::ok('cache', 'debug mode: only discovery.php is cached and checked for freshness');

            return $findings;
        }

        foreach (['discovery.php', 'routes.php', 'container.php'] as $file) {
            $findings[] = is_file($dir . '/' . $file)
                ? Finding::ok('cache', $file . ' present')
                : Finding::warning('cache', $file . ' is missing: the first request builds it; run keel:compile after deploying');
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function runtime(): array
    {
        $server = $this->server ?? $_SERVER;
        $configured = $server['KEEL_RUNTIME'] ?? getenv('KEEL_RUNTIME');
        $configured = is_string($configured) && $configured !== '' ? $configured : null;

        try {
            $runtime = Runtime::detect($this->server);
        } catch (\InvalidArgumentException $exception) {
            return [Finding::failure('runtime', $exception->getMessage() . ': use fpm, frankenphp or roadrunner')];
        }

        $findings = [Finding::ok('runtime', match (true) {
            $runtime instanceof FrankenPhpRuntime => 'FrankenPHP worker mode',
            $runtime instanceof RoadRunnerRuntime => 'RoadRunner worker',
            $runtime instanceof FpmRuntime => 'one request per process (php-fpm, mod_php, built-in server)',
            default => $runtime::class,
        } . ($configured === null ? ' (KEEL_RUNTIME unset, detected)' : ' (KEEL_RUNTIME=' . $configured . ')'))];

        if ($runtime instanceof RoadRunnerRuntime && !class_exists(PSR7Worker::class)) {
            $findings[] = Finding::failure('runtime', 'RoadRunner needs spiral/roadrunner-http: composer require spiral/roadrunner-http');
        }
        if ($runtime instanceof FrankenPhpRuntime && !function_exists('frankenphp_handle_request')) {
            $findings[] = Finding::ok('runtime', 'the FrankenPHP extension is only present inside the FrankenPHP binary; worker mode is verified when the server starts');
        }

        if (!$runtime instanceof FpmRuntime) {
            $maxRequests = $server['MAX_REQUESTS'] ?? null;
            $memory = MemoryGuard::fromEnvironment($this->server);
            $limits = [];
            if (is_numeric($maxRequests) && (int) $maxRequests > 0) {
                $limits[] = sprintf('after %d requests (MAX_REQUESTS)', (int) $maxRequests);
            }
            if ($memory->enabled()) {
                $limits[] = sprintf('above %s of memory (%s)', MemoryGuard::format($memory->limit()), isset($server[MemoryGuard::ENV]) ? MemoryGuard::ENV : '90% of memory_limit');
            }
            $findings[] = $limits === []
                ? Finding::warning('runtime', 'no MAX_REQUESTS and no memory limit: a slow leak would grow until the process dies; set ' . MemoryGuard::ENV)
                : Finding::ok('runtime', 'workers restart ' . implode(' or ', $limits));
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function packages(): array
    {
        if (!class_exists(InstalledVersions::class)) {
            return [Finding::ok('packages', 'Composer runtime API unavailable: installed packages not checked')];
        }

        $installed = [];
        $absent = [];
        foreach (self::OPTIONAL_PACKAGES as $package => $feature) {
            if (InstalledVersions::isInstalled($package)) {
                $installed[] = sprintf('%s %s', $package, InstalledVersions::getPrettyVersion($package) ?? '');
            } else {
                $absent[] = sprintf('%s (%s)', $package, $feature);
            }
        }

        $findings = [];
        if ($installed !== []) {
            $findings[] = Finding::ok('packages', 'installed: ' . implode(', ', $installed));
        }
        if ($absent !== []) {
            $findings[] = Finding::ok('packages', 'optional, not installed: ' . implode(', ', $absent));
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function container(): array
    {
        try {
            $definitions = AppBuilder::definitionsFor($this->app);
        } catch (\Throwable $exception) {
            return [Finding::failure('container', 'definitions cannot be assembled: ' . $exception->getMessage())];
        }

        $findings = [];
        foreach (new Diagnostics($definitions)->check() as $problem) {
            $findings[] = Finding::failure('container', $problem->getMessage(), $problem->report());
        }

        try {
            new Compiler($definitions)->compile();
            $findings[] = Finding::ok('container', sprintf(
                '%d service(s), %d alias(es), %d parameter(s); compiles',
                count($definitions->definitions()),
                count($definitions->aliases()),
                count($definitions->parameters()),
            ));
        } catch (ContainerException $exception) {
            $findings[] = Finding::failure('container', 'cannot be compiled: ' . $exception->getMessage(), $exception->report());
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function routes(): array
    {
        $routes = $this->app->router->compiled->routes();

        return [Finding::ok('routes', sprintf('%d route(s) from %d controller(s)', count($routes), count($this->app->controllers())))];
    }

    /**
     * @return list<Finding>
     */
    private function storage(): array
    {
        $findings = [];

        $session = $this->app->configs[SessionConfig::class] ?? null;
        if ($session instanceof SessionConfig && $session->storage === 'file') {
            if ($session->filePath === null) {
                $findings[] = Finding::warning('storage', 'sessions go to the system temp dir, shared with every local user: set SessionConfig::$filePath (for example var/sessions)');
            } else {
                $findings[] = self::directory('storage', 'session directory', $session->filePath);
            }
        }

        $cache = $this->app->configs[CacheConfig::class] ?? null;
        if ($cache instanceof CacheConfig && $cache->directory !== null && in_array($cache->driver, ['file', 'auto'], true)) {
            $findings[] = self::directory('storage', 'cache directory', $cache->directory);
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function health(): array
    {
        $checks = $this->app->container->tagged(HealthCheck::TAG);
        if ($checks === []) {
            return [Finding::ok('health', 'no health checks registered (tag a HealthCheck with keel.health)')];
        }

        $findings = [];
        foreach ($checks as $check) {
            if (!$check instanceof HealthCheck) {
                continue;
            }
            try {
                $result = $check->check();
            } catch (\Throwable $exception) {
                $findings[] = Finding::failure('health', sprintf('%s threw %s: %s', $check->name(), $exception::class, $exception->getMessage()));
                continue;
            }

            $message = $check->name() . ': ' . $result->status->value . ($result->detail === null ? '' : ' (' . $result->detail . ')');
            $findings[] = match ($result->status) {
                HealthStatus::Up => Finding::ok('health', $message),
                HealthStatus::Degraded => Finding::warning('health', $message),
                HealthStatus::Down => Finding::failure('health', $message),
            };
        }

        return $findings;
    }

    private static function directory(string $section, string $what, string $path): Finding
    {
        if (is_dir($path)) {
            return is_writable($path)
                ? Finding::ok($section, sprintf('%s %s is writable', $what, $path))
                : Finding::failure($section, sprintf('%s %s is not writable', $what, $path));
        }

        $parent = self::existingParent($path);

        return $parent !== null && is_writable($parent)
            ? Finding::ok($section, sprintf('%s %s does not exist yet and can be created', $what, $path))
            : Finding::failure($section, sprintf('%s %s does not exist and cannot be created', $what, $path));
    }

    private static function existingParent(string $path): ?string
    {
        $parent = dirname($path);
        while (!is_dir($parent)) {
            $next = dirname($parent);
            if ($next === $parent) {
                return null;
            }
            $parent = $next;
        }

        return $parent;
    }
}
