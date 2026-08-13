<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Metrics;

use Shopware\PrometheusExporter\Metrics\Struct\Metric;

/**
 * Scrape-time, host-local metric providers.
 *
 * @internal Not an extension point by design: metrics collected here are visible to this
 *           exporter only and never reach other telemetry transports (e.g. OpenTelemetry).
 *           Plugins must emit through the core telemetry abstraction (Meter/Telemetry)
 *           instead. Providers exist solely for host-local process data that cannot flow
 *           through core (OPcache, PHP-FPM).
 */
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