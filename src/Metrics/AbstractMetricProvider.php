<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Metrics;

use Shopware\PrometheusExporter\Metrics\Struct\Metric;
use Shopware\PrometheusExporter\Metrics\Struct\MetricValue;

abstract class AbstractMetricProvider implements MetricProviderInterface
{
    public function getName(): string
    {
        $className = static::class;
        $parts = explode('\\', $className);
        $lastPart = end($parts);
        
        if (str_ends_with($lastPart, 'MetricProvider')) {
            $lastPart = substr($lastPart, 0, -15);
        }
        
        return strtolower($lastPart);
    }
    
    /**
     * Helper to create a Prometheus gauge metric
     *
     * @param array<string, string|int|float|bool> $labels
     */
    protected function createGauge(string $name, float $value, string $help, array $labels = []): Metric
    {
        return new Metric(
            $name,
            [new MetricValue($value, $labels)],
            $help,
            Metric::TYPE_GAUGE
        );
    }
    
    /**
     * Helper to create a Prometheus counter metric
     *
     * @param array<string, string|int|float|bool> $labels
     */
    protected function createCounter(string $name, float $value, string $help, array $labels = []): Metric
    {
        return new Metric(
            $name,
            [new MetricValue($value, $labels)],
            $help,
            Metric::TYPE_COUNTER
        );
    }
    
    /**
     * Helper to create a multi-value metric
     *
     * @param array<MetricValue> $values
     */
    protected function createMetric(string $name, array $values, string $help, string $type = Metric::TYPE_GAUGE): Metric
    {
        return new Metric($name, $values, $help, $type);
    }
}