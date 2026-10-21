<?php

declare(strict_types=1);

namespace Keel\Health;

use Keel\Container\Attribute\TaggedIterator;
use Keel\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Answers the health endpoints before routing. Readiness runs every HealthCheck tagged
 * keel.health: 200 with {"status": "up"|"degraded"} or 503 with "down"; each check's result is
 * listed under "checks". Liveness answers 200 {"status": "up"} without running anything.
 */
final class HealthMiddleware implements MiddlewareInterface
{
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
                $result = HealthResult::down($e->getMessage());
            }
            $entry = ['status' => $result->status->value] + $result->data;
            if ($result->detail !== null) {
                $entry['detail'] = $result->detail;
            }
            $checks[$check->name()] = $entry;
            $overall = self::worse($overall, $result->status);
        }

        return ['status' => $overall->value, 'checks' => $checks];
    }

    private static function worse(HealthStatus $a, HealthStatus $b): HealthStatus
    {
        $rank = [HealthStatus::Up->value => 0, HealthStatus::Degraded->value => 1, HealthStatus::Down->value => 2];

        return $rank[$a->value] >= $rank[$b->value] ? $a : $b;
    }
}
