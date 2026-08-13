<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Metrics;

use Prometheus\CollectorRegistry;

/**
 * Prefer scraping the native PHP-FPM status page (`pm.status_path` with `?openmetrics`,
 * PHP >= 8.1) directly; this provider only exists for setups where that page cannot be
 * exposed to Prometheus.
 *
 * @internal
 */
class PHPFPMMetricProvider implements MetricProviderInterface
{
    private const GAUGES = [
        'phpfpm_listen_queue' => ['listen-queue', 'Number of requests in the queue of pending connections'],
        'phpfpm_active_processes' => ['active-processes', 'Number of active processes'],
        'phpfpm_idle_processes' => ['idle-processes', 'Number of idle processes'],
        'phpfpm_total_processes' => ['total-processes', 'Total number of processes'],
        'phpfpm_max_active_processes' => ['max-active-processes', 'Maximum number of active processes since FPM start'],
        'phpfpm_max_children_reached' => ['max-children-reached', 'Number of times the process limit has been reached'],
        'phpfpm_slow_requests' => ['slow-requests', 'Number of requests that exceeded the request_slowlog_timeout value'],
    ];

    public function collect(CollectorRegistry $registry): void
    {
        if (!\function_exists('fpm_get_status')) {
            return;
        }

        $status = @fpm_get_status();
        if (!\is_array($status)) {
            return;
        }

        foreach (self::GAUGES as $name => [$statusKey, $help]) {
            $registry
                ->getOrRegisterGauge('', $name, $help)
                ->set((float) $status[$statusKey]);
        }
    }
}
