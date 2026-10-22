<?php

declare(strict_types=1);

namespace Keel\Console\Install;

use Keel\Auth\Jwt\JwtAuthenticator;
use Keel\Cache\CacheProvider;
use Keel\Database\DbalProvider;
use Keel\Database\PdoProvider;
use Keel\Health\HealthMiddleware;
use Keel\Http\Middleware\CorsMiddleware;
use Keel\Http\Middleware\SecurityHeadersMiddleware;
use Keel\HttpClient\HttpClientProvider;
use Keel\I18n\I18nProvider;
use Keel\I18n\LocaleMiddleware;
use Keel\Log\MonologProvider;
use Keel\Metrics\MetricsMiddleware;
use Keel\OpenApi\OpenApiMiddleware;
use Keel\Redis\RedisProvider;
use Keel\Storage\StorageProvider;
use Keel\Telemetry\TracingMiddleware;
use Keel\View\LatteProvider;
use Keel\View\TwigProvider;

/**
 * One installable piece of Keel for keel:install: the Composer packages it needs, the provider
 * and middleware it registers, the config files it starts from.
 *
 * @internal used by keel:install
 */
final readonly class Component
{
    /**
     * @param list<string>                $packages   Composer packages
     * @param list<string>                $extensions PHP extensions it needs
     * @param list<class-string>          $providers  added to ServicesConfig in config/services.php
     * @param list<class-string>          $middleware added to HttpConfig in config/http.php
     * @param array<string, string>       $configs    config file name => source, written when absent
     * @param list<string>                $requires   components installed first
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $packages = [],
        public array $extensions = [],
        public array $providers = [],
        public array $middleware = [],
        public array $configs = [],
        public array $requires = [],
        public string $notes = '',
    ) {}

    /**
     * @return array<string, self>
     */
    public static function all(): array
    {
        $components = [
            new self('cache', 'PSR-16 cache: APCu, files, Redis or Memcached', providers: [CacheProvider::class], configs: [
                'cache.php' => self::config(['Keel\Cache\CacheConfig'], "new CacheConfig(driver: 'auto')"),
            ]),
            new self('redis', 'Redis connection, health check, Redis sessions, counters and metrics', extensions: ['redis'], providers: [RedisProvider::class], configs: [
                'redis.php' => self::config(['Keel\Config\Env', 'Keel\Redis\RedisConfig'], "static fn(Env \$env): RedisConfig => RedisConfig::fromUrl(\$env->get('REDIS_URL') ?? 'redis://127.0.0.1:6379', use: ['counters'])"),
            ], notes: "Set REDIS_URL; CacheConfig(driver: 'redis') puts the cache on it too."),
            new self('memcached', 'Memcached cache driver', extensions: ['memcached'], providers: [CacheProvider::class], configs: [
                'cache.php' => self::config(['Keel\Cache\CacheConfig'], "new CacheConfig(driver: 'memcached', servers: ['127.0.0.1:11211'])"),
            ]),
            new self('dbal', 'Doctrine DBAL connection with a health check', packages: ['doctrine/dbal'], providers: [DbalProvider::class], configs: [
                'database.php' => self::config(['Keel\Config\Env', 'Keel\Database\DbalConfig'], "static fn(Env \$env): DbalConfig => DbalConfig::fromUrl(\$env->require('DATABASE_URL'))"),
            ], notes: 'Set DATABASE_URL, e.g. pgsql://user:pass@localhost:5432/app.'),
            new self('pdo', 'Plain PDO connection with a health check', extensions: ['pdo'], providers: [PdoProvider::class], configs: [
                'database.php' => self::config(['Keel\Config\Env', 'Keel\Database\PdoConfig'], "static fn(Env \$env): PdoConfig => new PdoConfig(\$env->require('DATABASE_DSN'), \$env->get('DATABASE_USER'), \$env->get('DATABASE_PASSWORD'))"),
            ], notes: 'Set DATABASE_DSN (and DATABASE_USER / DATABASE_PASSWORD).'),
            new self('twig', 'Twig templates as the ViewRenderer', packages: ['twig/twig'], providers: [TwigProvider::class], configs: [
                'twig.php' => self::config(['Keel\Config\Env', 'Keel\View\TwigConfig'], "static fn(Env \$env, string \$projectDir): TwigConfig => new TwigConfig([\$projectDir . '/templates'], cache: \$projectDir . '/var/cache/twig')"),
            ], notes: 'Templates live in templates/.'),
            new self('latte', 'Latte templates as the ViewRenderer', packages: ['latte/latte'], providers: [LatteProvider::class], configs: [
                'latte.php' => self::config(['Keel\Config\Env', 'Keel\View\LatteConfig'], "static fn(Env \$env, string \$projectDir): LatteConfig => new LatteConfig(\$projectDir . '/templates', \$projectDir . '/var/cache/latte')"),
            ], notes: 'Templates live in templates/.'),
            new self('monolog', 'Monolog behind the PSR-3 logger and log channels', packages: ['monolog/monolog'], providers: [MonologProvider::class], configs: [
                'logger.php' => self::config(['Keel\Log\LoggerConfig'], 'new LoggerConfig()'),
            ]),
            new self('storage', 'File storage on local disks', providers: [StorageProvider::class], configs: [
                'storage.php' => self::config(['Keel\Config\Env', 'Keel\Storage\DiskConfig', 'Keel\Storage\StorageConfig'], "static fn(Env \$env, string \$projectDir): StorageConfig => new StorageConfig(['local' => DiskConfig::local(\$projectDir . '/var/storage')])"),
            ]),
            new self('flysystem', 'File storage on Flysystem adapters (S3, SFTP, ...)', packages: ['league/flysystem'], requires: ['storage'], notes: 'Register a League\Flysystem\FilesystemOperator service and add a DiskConfig::flysystem() disk in config/storage.php.'),
            new self('i18n', 'Translations, plural rules and locale negotiation', providers: [I18nProvider::class], middleware: [LocaleMiddleware::class], configs: [
                'i18n.php' => self::config(['Keel\Config\Env', 'Keel\I18n\I18nConfig'], "static fn(Env \$env, string \$projectDir): I18nConfig => new I18nConfig(\$projectDir . '/translations', ['en'])"),
            ], notes: 'Messages live in translations/<locale>/messages.php.'),
            new self('http-client', 'PSR-18 HTTP client on cURL', extensions: ['curl'], providers: [HttpClientProvider::class], configs: [
                'http_client.php' => self::config(['Keel\HttpClient\HttpClientConfig'], 'new HttpClientConfig()'),
            ]),
            new self('jwt', 'JWT bearer authentication (shared secret, public keys or JWKS)', configs: [
                'jwt.php' => self::config(['Keel\Auth\Jwt\JwtConfig', 'Keel\Config\Env'], "static fn(Env \$env): JwtConfig => new JwtConfig(algorithms: ['RS256'], jwksUrl: \$env->require('JWT_JWKS_URL'), issuer: \$env->get('JWT_ISSUER'), audience: \$env->get('JWT_AUDIENCE'))"),
                'auth.php' => self::config(['Keel\Auth\AuthConfig', JwtAuthenticator::class], 'new AuthConfig([JwtAuthenticator::class])'),
            ], requires: ['http-client', 'cache'], notes: 'Set JWT_JWKS_URL (and JWT_ISSUER / JWT_AUDIENCE), or switch config/jwt.php to a secret or public keys. An existing config/auth.php is kept: add JwtAuthenticator::class to it yourself.'),
            new self('security-headers', 'HSTS, CSP, X-Frame-Options and friends on every response', middleware: [SecurityHeadersMiddleware::class], configs: [
                'security_headers.php' => self::config(['Keel\Http\Middleware\SecurityHeadersConfig'], 'SecurityHeadersConfig::api()'),
            ]),
            new self('cors', 'CORS preflights and headers', middleware: [CorsMiddleware::class], configs: [
                'cors.php' => self::config(['Keel\Http\Middleware\CorsConfig'], "new CorsConfig(allowedOrigins: ['https://app.example.com'])"),
            ]),
            new self('health', '/health and /health/live endpoints', middleware: [HealthMiddleware::class]),
            new self('metrics', 'Prometheus metrics at /metrics', middleware: [MetricsMiddleware::class]),
            new self('openapi', 'OpenAPI document at /openapi.json and a reference at /docs', middleware: [OpenApiMiddleware::class], configs: [
                'openapi.php' => self::config(['Keel\OpenApi\OpenApiConfig'], "new OpenApiConfig(title: 'API', version: '1.0.0')"),
            ]),
            new self('opentelemetry', 'OpenTelemetry spans for every request', packages: ['open-telemetry/sdk'], middleware: [TracingMiddleware::class], notes: 'Configure the SDK exporter through the OTEL_* environment variables.'),
            new self('roadrunner', 'RoadRunner worker runtime', packages: ['spiral/roadrunner-http'], notes: 'Add a .rr.yaml (see examples/hello/.rr.yaml in the framework) and start with ./rr serve.'),
            new self('console', 'Symfony Console for bin/console and the keel:* commands', packages: ['symfony/console']),
        ];

        $byName = [];
        foreach ($components as $component) {
            $byName[$component->name] = $component;
        }

        return $byName;
    }

    /**
     * A config file returning $expression, with these imports.
     *
     * @param list<string> $imports
     */
    private static function config(array $imports, string $expression): string
    {
        sort($imports);

        return "<?php\n\ndeclare(strict_types=1);\n\n"
            . implode('', array_map(static fn(string $class): string => 'use ' . $class . ";\n", $imports))
            . "\nreturn " . $expression . ";\n";
    }
}
