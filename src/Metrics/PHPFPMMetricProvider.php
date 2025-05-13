<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Metrics;

use Shopware\PrometheusExporter\Metrics\Struct\Metric;

class PHPFPMMetricProvider extends AbstractMetricProvider
{
    /**
     * @return array<Metric>
     */
    public function getMetrics(): array
    {
        if (!function_exists('fpm_get_status')) {
            return [];
        }

        $status = @fpm_get_status();
        if (!$status) {
            return [];
        }

        return [
            // Add basic status metrics
            $this->createGauge(
                'phpfpm_listen_queue',
                (float) ($status['listen_queue'] ?? 0),
                'Number of requests in the queue of pending connections'
            ),

            $this->createGauge(
                'phpfpm_active_processes',
                (float) ($status['active processes'] ?? 0),
                'Number of active processes'
            ),

            $this->createGauge(
                'phpfpm_idle_processes',
                (float) ($status['idle processes'] ?? 0),
                'Number of idle processes'
            ),

            $this->createGauge(
                'phpfpm_total_processes',
                (float) ($status['total processes'] ?? 0),
                'Total number of processes'
            ),

            $this->createGauge(
                'phpfpm_max_active_processes',
                (float) ($status['max active processes'] ?? 0),
                'Maximum number of active processes since FPM start'
            ),

            $this->createGauge(
                'phpfpm_max_children_reached',
                (float) ($status['max children reached'] ?? 0),
                'Number of times the process limit has been reached'
            ),

            $this->createGauge(
                'phpfpm_slow_requests',
                (float) ($status['slow requests'] ?? 0),
                'Number of requests that exceeded the request_slowlog_timeout value'
            ),
        ];
    }
}