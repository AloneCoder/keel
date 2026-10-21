<?php

declare(strict_types=1);

namespace Keel\Metrics;

/**
 * Counters, gauges and histograms with labels, rendered in the Prometheus text format.
 * Metric metadata lives in the registry (every worker registers the same metrics at boot);
 * samples live in the storage, so APCu aggregates the workers of one server. Values are kept
 * as integers: gauges and histogram sums are scaled by 1e6, which keeps APCu's atomic
 * increments usable for durations.
 */
final class Registry
{
    private const int SCALE = 1_000_000;

    private const string SEPARATOR = "\x1f";

    private readonly MetricsStorage $storage;

    /** @var array<string, array{type: string, help: string, labels: list<string>, buckets: list<float>}> */
    private array $metrics = [];

    public function __construct(?MetricsStorage $storage = null)
    {
        $this->storage = $storage ?? (ApcuStorage::available() ? new ApcuStorage() : new InMemoryStorage());
    }

    /**
     * @param list<string> $labels
     */
    public function counter(string $name, string $help, array $labels = []): self
    {
        return $this->register($name, 'counter', $help, $labels, []);
    }

    /**
     * @param list<string> $labels
     */
    public function gauge(string $name, string $help, array $labels = []): self
    {
        return $this->register($name, 'gauge', $help, $labels, []);
    }

    /**
     * @param list<string> $labels
     * @param list<float>  $buckets upper bounds, ascending; +Inf is added
     */
    public function histogram(string $name, string $help, array $labels = [], array $buckets = MetricsConfig::DEFAULT_BUCKETS): self
    {
        return $this->register($name, 'histogram', $help, $labels, $buckets);
    }

    /**
     * @param list<string|int|float> $labelValues
     */
    public function inc(string $name, array $labelValues = [], int $by = 1): void
    {
        $this->storage->add($this->key($name, 'counter', $labelValues), $by);
    }

    /**
     * @param list<string|int|float> $labelValues
     */
    public function set(string $name, array $labelValues, float $value): void
    {
        $this->storage->set($this->key($name, 'gauge', $labelValues), (int) round($value * self::SCALE));
    }

    /**
     * @param list<string|int|float> $labelValues
     */
    public function observe(string $name, array $labelValues, float $value): void
    {
        $base = $this->key($name, 'histogram', $labelValues);
        foreach ($this->metrics[$name]['buckets'] as $bound) {
            if ($value <= $bound) {
                $this->storage->add($base . self::SEPARATOR . self::bound($bound), 1);
            }
        }
        $this->storage->add($base . self::SEPARATOR . '+Inf', 1);
        $this->storage->add($base . self::SEPARATOR . 'sum', (int) round($value * self::SCALE));
    }

    public function has(string $name): bool
    {
        return isset($this->metrics[$name]);
    }

    /**
     * Prometheus text exposition (version 0.0.4), metrics in registration order.
     */
    public function render(): string
    {
        $samples = [];
        foreach ($this->storage->all() as $key => $value) {
            $parts = explode(self::SEPARATOR, $key);
            $samples[$parts[0]][] = [$parts[1] ?? '', $parts[2] ?? '', $value];
        }

        $out = '';
        foreach ($this->metrics as $name => $metric) {
            $out .= sprintf("# HELP %s %s\n# TYPE %s %s\n", $name, self::escapeHelp($metric['help']), $name, $metric['type']);
            foreach ($samples[$name] ?? [] as [$labels, $suffix, $value]) {
                $out .= match ($metric['type']) {
                    'counter' => sprintf("%s%s %d\n", $name, $labels, $value),
                    'gauge' => sprintf("%s%s %s\n", $name, $labels, self::float($value / self::SCALE)),
                    default => self::histogramLine($name, $labels, $suffix, $value),
                };
            }
        }

        return $out;
    }

    /**
     * @param list<string> $labels
     * @param list<float>  $buckets
     */
    private function register(string $name, string $type, string $help, array $labels, array $buckets): self
    {
        if (preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid metric name "%s"', $name));
        }
        sort($buckets);
        $this->metrics[$name] = ['type' => $type, 'help' => $help, 'labels' => $labels, 'buckets' => $buckets];

        return $this;
    }

    /**
     * @param list<string|int|float> $labelValues
     */
    private function key(string $name, string $type, array $labelValues): string
    {
        $metric = $this->metrics[$name] ?? throw new \LogicException(sprintf('Metric "%s" is not registered', $name));
        if ($metric['type'] !== $type) {
            throw new \LogicException(sprintf('Metric "%s" is a %s, not a %s', $name, $metric['type'], $type));
        }
        if (count($labelValues) !== count($metric['labels'])) {
            throw new \LogicException(sprintf('Metric "%s" expects %d label values, got %d', $name, count($metric['labels']), count($labelValues)));
        }

        $pairs = [];
        foreach ($metric['labels'] as $i => $label) {
            $pairs[] = $label . '="' . self::escapeLabel((string) $labelValues[$i]) . '"';
        }

        return $name . self::SEPARATOR . ($pairs === [] ? '' : '{' . implode(',', $pairs) . '}');
    }

    private static function histogramLine(string $name, string $labels, string $suffix, int $value): string
    {
        if ($suffix === 'sum') {
            return sprintf("%s_sum%s %s\n", $name, $labels, self::float($value / self::SCALE));
        }
        if ($suffix === '+Inf') {
            $inner = $labels === '' ? 'le="+Inf"' : substr($labels, 1, -1) . ',le="+Inf"';

            return sprintf("%s_bucket{%s} %d\n%s_count%s %d\n", $name, $inner, $value, $name, $labels, $value);
        }
        $inner = $labels === '' ? 'le="' . $suffix . '"' : substr($labels, 1, -1) . ',le="' . $suffix . '"';

        return sprintf("%s_bucket{%s} %d\n", $name, $inner, $value);
    }

    private static function bound(float $bound): string
    {
        return self::float($bound);
    }

    private static function float(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

        return $text === '' || $text === '-0' ? '0' : $text;
    }

    private static function escapeLabel(string $value): string
    {
        return str_replace(['\\', "\n", '"'], ['\\\\', '\\n', '\\"'], $value);
    }

    private static function escapeHelp(string $value): string
    {
        return str_replace(['\\', "\n"], ['\\\\', '\\n'], $value);
    }
}
