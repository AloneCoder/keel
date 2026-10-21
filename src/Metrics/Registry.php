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
        $this->storage->set($this->key($name, 'gauge', $labelValues), self::scaled($value));
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
        $this->storage->add($base . self::SEPARATOR . 'sum', self::scaled($value));
    }

    public function has(string $name): bool
    {
        return isset($this->metrics[$name]);
    }

    /**
     * Prometheus text exposition (version 0.0.4): metrics in registration order, histogram
     * buckets in ascending order with a line for every configured bucket, then +Inf, _count, _sum.
     */
    public function render(): string
    {
        /** @var array<string, array<string, array<string, int>>> $samples metric => labels => suffix => value */
        $samples = [];
        foreach ($this->storage->all() as $key => $value) {
            $parts = explode(self::SEPARATOR, $key);
            $samples[$parts[0]][$parts[1] ?? ''][$parts[2] ?? ''] = $value;
        }

        $out = '';
        foreach ($this->metrics as $name => $metric) {
            $out .= sprintf("# HELP %s %s\n# TYPE %s %s\n", $name, self::escapeHelp($metric['help']), $name, $metric['type']);
            foreach ($samples[$name] ?? [] as $labels => $values) {
                $labels = (string) $labels;
                $out .= match ($metric['type']) {
                    'counter' => sprintf("%s%s %d\n", $name, $labels, $values[''] ?? 0),
                    'gauge' => sprintf("%s%s %s\n", $name, $labels, self::float(($values[''] ?? 0) / self::SCALE)),
                    default => self::histogramLines($name, $labels, $metric['buckets'], $values),
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

    /**
     * @param list<float>        $buckets
     * @param array<string, int> $values bucket bound, "+Inf" or "sum" => value
     */
    private static function histogramLines(string $name, string $labels, array $buckets, array $values): string
    {
        $inner = $labels === '' ? '' : substr($labels, 1, -1) . ',';
        $out = '';
        foreach ($buckets as $bound) {
            $bound = self::bound($bound);
            $out .= sprintf("%s_bucket{%sle=\"%s\"} %d\n", $name, $inner, $bound, $values[$bound] ?? 0);
        }
        $count = $values['+Inf'] ?? 0;

        return $out
            . sprintf("%s_bucket{%sle=\"+Inf\"} %d\n", $name, $inner, $count)
            . sprintf("%s_count%s %d\n", $name, $labels, $count)
            . sprintf("%s_sum%s %s\n", $name, $labels, self::float(($values['sum'] ?? 0) / self::SCALE));
    }

    /**
     * Shortest round-trip spelling of a bucket bound ("0.1", "1", "2.5e-7"), so distinct bounds never share a line.
     */
    private static function bound(float $bound): string
    {
        $text = (string) json_encode($bound);

        return str_ends_with($text, '.0') ? substr($text, 0, -2) : $text;
    }

    private static function float(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

        return $text === '' || $text === '-0' ? '0' : $text;
    }

    /**
     * Value scaled to an integer, clamped to the integer range instead of overflowing.
     */
    private static function scaled(float $value): int
    {
        $scaled = round($value * self::SCALE);
        if (is_nan($scaled)) {
            return 0;
        }
        if ($scaled >= 9.2e18) {
            return \PHP_INT_MAX;
        }
        if ($scaled <= -9.2e18) {
            return \PHP_INT_MIN;
        }

        return (int) $scaled;
    }

    private static function escapeLabel(string $value): string
    {
        return str_replace(['\\', "\n", '"', self::SEPARATOR], ['\\\\', '\\n', '\\"', ''], $value);
    }

    private static function escapeHelp(string $value): string
    {
        return str_replace(['\\', "\n"], ['\\\\', '\\n'], $value);
    }
}
