<?php

declare(strict_types=1);

namespace Keel\Tests\App;

use Keel\App\App;
use Keel\App\Doctor\Doctor;
use Keel\App\Doctor\Finding;
use Keel\App\Doctor\Severity;
use Keel\Config\Env;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Doctor::class)]
#[CoversClass(Finding::class)]
#[CoversClass(Severity::class)]
final class DoctorTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/keel-tests/' . getmypid() . '/doctor-' . uniqid();
    }

    private function app(string $project, string $env = 'dev', bool $debug = true): App
    {
        return App::boot(__DIR__ . '/Fixtures/' . $project, new Env(['APP_ENV' => $env, 'APP_DEBUG' => $debug ? '1' : '0', 'KEEL_CACHE_DIR' => $this->cacheDir]));
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<string>
     */
    private static function lines(array $findings, ?Severity $severity = null): array
    {
        $lines = [];
        foreach ($findings as $finding) {
            if ($severity === null || $finding->severity === $severity) {
                $lines[] = $finding->section . ': ' . $finding->message;
            }
        }

        return $lines;
    }

    public function testAHealthyProjectHasNoFailures(): void
    {
        $findings = new Doctor($this->app('Project'), ['KEEL_RUNTIME' => 'frankenphp', 'MAX_REQUESTS' => '500'])->diagnose();

        self::assertTrue(Doctor::healthy($findings));
        self::assertSame([], self::lines($findings, Severity::Failure));
        $lines = self::lines($findings);
        self::assertContains('php: PHP ' . \PHP_VERSION . ' (cli)', $lines);
        self::assertContains('config: APP_ENV=dev, debug on', $lines);
        self::assertContains('config: discovery path ' . __DIR__ . '/Fixtures/Project/Src', $lines);
        self::assertContains('cache: debug mode: only discovery.php is cached and checked for freshness', $lines);
        self::assertContains('runtime: FrankenPHP worker mode (KEEL_RUNTIME=frankenphp)', $lines);
        self::assertContains('runtime: workers restart after 500 requests (MAX_REQUESTS)' . (ini_get('memory_limit') === '-1' ? '' : ' or above ' . \Keel\Runtime\MemoryGuard::format(\Keel\Runtime\MemoryGuard::fromEnvironment([])->limit()) . ' of memory (90% of memory_limit)'), $lines);
        self::assertContains('health: ping: up (pong)', $lines);
        self::assertMatchesRegularExpression('/^container: \d+ service\(s\), \d+ alias\(es\), \d+ parameter\(s\); compiles$/', implode("\n", array_filter($lines, static fn(string $l): bool => str_starts_with($l, 'container:'))));
        self::assertMatchesRegularExpression('/^routes: \d+ route\(s\) from 2 controller\(s\)$/', implode("\n", array_filter($lines, static fn(string $l): bool => str_starts_with($l, 'routes:'))));

        $without = new Doctor($this->app('Project'))->diagnose(healthChecks: false);
        self::assertNotContains('health: ping: up (pong)', self::lines($without));
    }

    public function testBrokenContainerAndRiskyStorageAreReported(): void
    {
        $findings = new Doctor($this->app('Broken'), ['KEEL_RUNTIME' => 'roadrunner', 'KEEL_MAX_MEMORY' => '0'])->diagnose();

        self::assertFalse(Doctor::healthy($findings));
        $failures = array_values(array_filter($findings, static fn(Finding $f): bool => $f->severity === Severity::Failure));
        self::assertCount(1, $failures);
        self::assertSame('container', $failures[0]->section);
        self::assertSame('Service "missing.logger" is not registered and cannot be autowired (resolution chain: broken.mailer)', $failures[0]->message);
        self::assertStringContainsString("Resolution path:\nbroken.mailer -> missing.logger", (string) $failures[0]->detail);

        $warnings = self::lines($findings, Severity::Warning);
        self::assertContains('storage: sessions go to the system temp dir, shared with every local user: set SessionConfig::$filePath (for example var/sessions)', $warnings);
        self::assertContains('runtime: no MAX_REQUESTS and no memory limit: a slow leak would grow until the process dies; set KEEL_MAX_MEMORY', $warnings);
    }

    public function testProductionWithoutCachesAndABadRuntimeNameAreFlagged(): void
    {
        $findings = new Doctor($this->app('Project', 'prod', false), ['KEEL_RUNTIME' => 'swoole'])->diagnose(healthChecks: false);

        $lines = self::lines($findings, Severity::Failure);
        self::assertSame(['runtime: Unknown KEEL_RUNTIME "swoole": use fpm, frankenphp or roadrunner'], $lines);
        $warnings = self::lines($findings, Severity::Warning);
        self::assertContains('cache: routes.php present', self::lines($findings, Severity::Ok), 'a production boot wrote the caches');
        self::assertNotContains('cache: container.php is missing: the first request builds it; run keel:compile after deploying', $warnings);

        $app = $this->app('Project', 'prod', false);
        $app->clearCache();
        $missing = self::lines(new Doctor($app, [])->diagnose(healthChecks: false), Severity::Warning);
        self::assertContains('cache: container.php is missing: the first request builds it; run keel:compile after deploying', $missing);
        self::assertContains('cache: routes.php is missing: the first request builds it; run keel:compile after deploying', $missing);
    }
}
