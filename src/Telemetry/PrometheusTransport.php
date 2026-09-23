<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Telemetry;

use Prometheus\CollectorRegistry;
use Shopware\Core\Framework\Telemetry\Metrics\Config\MetricConfig;
use Shopware\Core\Framework\Telemetry\Metrics\Metric\Metric;
use Shopware\Core\Framework\Telemetry\Metrics\Metric\Type;
use Shopware\Core\Framework\Telemetry\Metrics\MetricTransportInterface;

/**
 * @internal
 */
class PrometheusTransport implements MetricTransportInterface
{
    /**
     * @var list<Metric>
     */
    private array $buffer = [];

    /**
     * @param array<string, MetricConfig> $metricConfigs indexed by metric name
     */
    public function __construct(
        private readonly CollectorRegistry $registry,
        private readonly array $metricConfigs,
        private readonly ?string $namespace,
        private readonly bool $buffered,
    ) {
    }

    public function emit(Metric $metric): void
    {
        if (!$this->buffered) {
            $this->write($metric);

            return;
        }

        $this->buffer[] = $metric;
    }

    public function flush(): void
    {
        $buffer = $this->buffer;
        $this->buffer = [];

        foreach ($buffer as $metric) {
            $this->write($metric);
        }
    }

    private function write(Metric $metric): void
    {
        $namespace = $this->namespace === null ? '' : MetricNaming::sanitizeMetricName($this->namespace);
        $name = MetricNaming::sanitizeMetricName($metric->name);

        $labels = $metric->labels;
        \ksort($labels); // deterministic series identity, independent of emitter argument order
        $labelNames = \array_map(MetricNaming::sanitizeLabelName(...), \array_keys($labels));
        $labelValues = \array_map(self::formatLabelValue(...), \array_values($labels));

        $value = (float) $metric->value;
        $help = $metric->description;

        match ($metric->type) {
            Type::COUNTER => $this->registry
                ->getOrRegisterCounter($namespace, $name, $help, $labelNames)
                ->incBy($value, $labelValues),
            Type::GAUGE => $this->registry
                ->getOrRegisterGauge($namespace, $name, $help, $labelNames)
                ->set($value, $labelValues),
            // Up-down emissions are deltas: promphp implements Gauge::incBy() as an atomic
            // hIncrByFloat, and TYPE gauge is the OTel mapping for non-monotonic sums.
            Type::UPDOWN_COUNTER => $this->registry
                ->getOrRegisterGauge($namespace, $name, $help, $labelNames)
                ->incBy($value, $labelValues),
            Type::HISTOGRAM => $this->registry
                ->getOrRegisterHistogram($namespace, $name, $help, $labelNames, $this->buckets($metric->name))
                ->observe($value, $labelValues),
        };
    }

    /**
     * @return list<float>|null promphp falls back to its default buckets on null
     */
    private function buckets(string $metricName): ?array
    {
        $buckets = ($this->metricConfigs[$metricName] ?? null)?->parameters['buckets'] ?? null;
        if (!\is_array($buckets)) {
            return null;
        }

        return \array_values(\array_map(floatval(...), $buckets));
    }

    private static function formatLabelValue(string|bool|float|int $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
