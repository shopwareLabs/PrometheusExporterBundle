<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Telemetry;

/**
 * Prometheus naming rules shared by the transport (stored metrics) and the scrape-time
 * metric pipeline.
 *
 * @internal
 */
final class MetricNaming
{
    public static function sanitizeMetricName(string $name): string
    {
        return (string) \preg_replace('/[^a-zA-Z0-9_:]/', '_', $name);
    }

    public static function sanitizeLabelName(string $name): string
    {
        return (string) \preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
    }

    private function __construct()
    {
    }
}
