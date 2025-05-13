<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Metrics;

use Shopware\PrometheusExporter\Metrics\Struct\Metric;

interface MetricProviderInterface
{
    /**
     * Returns an array of metrics
     * 
     * @return array<Metric>
     */
    public function getMetrics(): array;
    
    /**
     * Get the name of the metric provider
     */
    public function getName(): string;
}