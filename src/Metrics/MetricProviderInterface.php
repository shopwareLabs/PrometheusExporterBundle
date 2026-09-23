<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Metrics;

use Prometheus\CollectorRegistry;

/**
 * Scrape-time, host-local metric providers. Implementations write current values into the
 * per-scrape registry passed to collect(); the endpoint renders them alongside the stored
 * telemetry metrics.
 *
 * @internal Not an extension point by design: metrics collected here are visible to this
 *           exporter only and never reach other telemetry transports (e.g. OpenTelemetry).
 *           Plugins must emit through the core telemetry abstraction (Meter/Telemetry)
 *           instead. Providers exist solely for host-local process data that cannot flow
 *           through core (OPcache, PHP-FPM).
 */
interface MetricProviderInterface
{
    public function collect(CollectorRegistry $registry): void;
}
