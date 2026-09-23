<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Telemetry;

use Prometheus\CollectorRegistry;
use Shopware\Core\Framework\Telemetry\Metrics\Config\TransportConfig;
use Shopware\Core\Framework\Telemetry\Metrics\Factory\MetricTransportFactoryInterface;
use Shopware\Core\Framework\Telemetry\Metrics\MetricTransportInterface;

/**
 * @internal
 */
class PrometheusTransportFactory implements MetricTransportFactoryInterface
{
    public const WRITE_MODE_BUFFERED = 'buffered';

    public const WRITE_MODE_DIRECT = 'direct';

    public function __construct(
        private readonly CollectorRegistry $registry,
        private readonly string $writeMode,
    ) {
    }

    public function create(TransportConfig $transportConfig): MetricTransportInterface
    {
        $metricConfigs = [];
        foreach ($transportConfig->metricsConfig as $metricConfig) {
            $metricConfigs[$metricConfig->name] = $metricConfig;
        }

        return new PrometheusTransport(
            $this->registry,
            $metricConfigs,
            $transportConfig->namespace,
            $this->writeMode === self::WRITE_MODE_BUFFERED,
        );
    }
}
