<?php

declare(strict_types=1);

namespace Keel\Health;

use Keel\Container\Attribute\TaggedIterator;
use Keel\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Answers the health endpoints before routing. Readiness runs every HealthCheck tagged
 * keel.health: 200 with {"status": "up"|"degraded"} or 503 with "down"; each check's result is
 * listed under "checks". Liveness answers 200 {"status": "up"} without running anything.
 *
 * A check that throws, or returns a result carrying an exception, is reported as down. The
 * exception is logged; its message appears in the report only with HealthConfig::$exposeDetails,
 * because connection errors tend to name hosts, users and paths.
 */
final class HealthMiddleware implements MiddlewareInterface
{
    public const string HIDDEN_DETAIL = 'check failed';

    private readonly HealthConfig $config;

    /** @var list<HealthCheck> */
    private readonly array $checks;

    /**
     * @param iterable<HealthCheck> $checks
     */
    public function __construct(
        private readonly Responder $responder,
        #[TaggedIterator(HealthCheck::TAG)]
        iterable $checks = [],
        ?HealthConfig $config = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->config = $config ?? new HealthConfig();
        $this->checks = is_array($checks) ? array_values($checks) : iterator_to_array($checks, false);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true) || ($path !== $this->config->path && $path !== $this->config->livenessPath)) {
            return $handler->handle($request);
        }

        $report = $path === $this->config->livenessPath ? ['status' => HealthStatus::Up->value] : $this->report();

        return $this->responder->json($report, $report['status'] === HealthStatus::Down->value ? 503 : 200)
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @return array{status: string, checks: array<string, array<string, mixed>>}
     */
    public function report(): array
    {
        $overall = HealthStatus::Up;
        $checks = [];
        foreach ($this->checks as $check) {
            try {
                $result = $check->check();
            } catch (\Throwable $e) {
                $result = HealthResult::down($e->getMessage(), exception: $e);
            }
            $entry = ['status' => $result->status->value] + $result->data;
            $detail = $this->detail($check, $result);
            if ($detail !== null) {
                $entry['detail'] = $detail;
            }
            $checks[$check->name()] = $entry;
            $overall = self::worse($overall, $result->status);
        }

        return ['status' => $overall->value, 'checks' => $checks];
    }

    private function detail(HealthCheck $check, HealthResult $result): ?string
    {
        if ($result->exception === null) {
            return $result->detail;
        }
        $this->logger?->error('Health check "{check}" failed: {message}', [
            'check' => $check->name(),
            'message' => $result->exception->getMessage(),
            'exception' => $result->exception,
        ]);

        return $this->config->exposeDetails ? $result->detail : self::HIDDEN_DETAIL;
    }

    private static function worse(HealthStatus $a, HealthStatus $b): HealthStatus
    {
        $rank = [HealthStatus::Up->value => 0, HealthStatus::Degraded->value => 1, HealthStatus::Down->value => 2];

        return $rank[$a->value] >= $rank[$b->value] ? $a : $b;
    }
}
