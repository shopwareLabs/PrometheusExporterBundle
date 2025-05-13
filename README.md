# Prometheus Exporter for Shopware

This Shopware 6 plugin provides a Prometheus metrics endpoint that can be used to monitor your Shopware instance.

## Installation

```bash
composer require shopware/prometheus-exporter
```

**Important**: After installation, you need to update the bundle configuration in `config/bundles.php`:

```php
return [
    // ... other bundles
    Shopware\PrometheusExporter\PrometheusExporterBundle::class => ['all' => true],
];
```

## Configuration

### Access Control

You can configure the allowed IP addresses that can access the metrics endpoint in your `config/packages/prometheus_metrics.yaml` file:

```yaml
prometheus_metrics:
    allowed_ips:
        - 127.0.0.1
        - ::1
        - 10.0.0.0/8
```

By default, only localhost (127.0.0.1 and ::1) can access the metrics endpoint.

### Security Considerations

The metrics endpoint can expose sensitive information about your system. Always:

1. Restrict access to trusted IP addresses only
2. Consider using a reverse proxy like Nginx or Apache with additional authentication
3. In production environments, you might want to use a dedicated metrics collector (like Prometheus Node Exporter) instead of exposing metrics directly
4. Never expose metrics publicly without authentication

For high-security environments, consider using a pull-based approach where your monitoring system connects to your server over a secure tunnel to access the metrics endpoint locally.

## Metrics

The plugin provides the following metrics:

### PHP Version and Info Metrics

- `php_version_info`: Information about the PHP version with labels for major, minor, and patch versions
- PHP version metrics include labels for:
  - `version`: Full PHP version string
  - `major`, `minor`, `patch`: Individual version components
  - `sapi`: PHP SAPI name
  - `zts`: Whether PHP was built with thread safety (true/false)
  - `debug`: Whether PHP was built with debugging enabled (true/false)

### OPcache Metrics

- `opcache_memory_used_bytes`: Amount of OPcache memory used in bytes
- `opcache_memory_free_bytes`: Amount of OPcache memory free in bytes
- `opcache_memory_wasted_bytes`: Amount of OPcache memory wasted in bytes
- `opcache_memory_wasted_percentage`: Percentage of OPcache memory that is wasted
- `opcache_hits`: Number of cache hits
- `opcache_misses`: Number of cache misses
- `opcache_hit_rate_percentage`: OPcache hit rate as a percentage
- `opcache_scripts_count`: Number of scripts cached in OPcache
- `opcache_keys_count`: Number of keys cached in OPcache
- `opcache_max_keys_count`: Maximum number of keys that can be cached in OPcache
- `opcache_fullness_percentage`: OPcache fullness as a percentage (cached keys / max keys)
- `opcache_restarts_count`: Number of out-of-memory restarts of OPcache
- `opcache_enabled`: Flag indicating if OPcache is enabled (1 = enabled, 0 = disabled)
- `opcache_memory_size_bytes`: OPcache memory size in bytes
- `opcache_max_accelerated_files`: Maximum number of files that can be accelerated by OPcache
- `opcache_max_wasted_percentage`: Maximum percentage of wasted memory before OPcache restarts

### PHP-FPM Metrics

- `phpfpm_listen_queue`: Number of requests in the queue of pending connections
- `phpfpm_active_processes`: Number of active processes
- `phpfpm_idle_processes`: Number of idle processes
- `phpfpm_total_processes`: Total number of processes
- `phpfpm_max_active_processes`: Maximum number of active processes since FPM start
- `phpfpm_max_children_reached`: Number of times the process limit has been reached
- `phpfpm_slow_requests`: Number of requests that exceeded the request_slowlog_timeout value

### Queue Metrics

- `symfony_messenger_messages`: Number of messages in each Symfony Messenger queue

### OpenSearch Metrics

#### Cluster Status
- `opensearch_cluster_status`: OpenSearch cluster status (0=green, 1=yellow, 2=red)
  - Includes labels to easily check specific states

#### Node Metrics (Per Node and Total)
- `opensearch_jvm_memory_used_bytes`: JVM heap memory used in bytes
- `opensearch_jvm_memory_max_bytes`: JVM heap maximum memory in bytes
- `opensearch_jvm_memory_used_percentage`: JVM heap memory used percentage
- `opensearch_disk_total_bytes`: Total disk space in bytes
- `opensearch_disk_used_bytes`: Used disk space in bytes
- `opensearch_disk_used_percentage`: Used disk space percentage
- `opensearch_jvm_memory_used_bytes_total`: Total JVM heap memory used across all nodes
- `opensearch_jvm_memory_max_bytes_total`: Total JVM heap maximum memory across all nodes
- `opensearch_jvm_memory_used_percentage_total`: Total JVM heap memory usage percentage
- `opensearch_disk_total_bytes_total`: Total disk space across all nodes
- `opensearch_disk_used_bytes_total`: Total used disk space across all nodes
- `opensearch_disk_used_percentage_total`: Total disk space usage percentage

#### Index Metrics
- `opensearch_index_documents_count`: Number of documents in each OpenSearch index
- `opensearch_index_size_bytes`: Size of each OpenSearch index in bytes
- `opensearch_documents_total`: Total number of documents across all indices
- `opensearch_size_bytes_total`: Total size of all indices in bytes
- `opensearch_indices_count`: Total number of indices

## Adding Custom Metrics

You can add your own metrics by creating a service that implements the `Shopware\PrometheusExporter\Metrics\MetricProviderInterface` interface and tagging it with `shopware.prometheus.metrics`.

Example:

```php
<?php declare(strict_types=1);

namespace YourNamespace\Metrics;

use Shopware\PrometheusExporter\Metrics\AbstractMetricProvider;
use Shopware\PrometheusExporter\Metrics\Struct\Metric;
use Shopware\PrometheusExporter\Metrics\Struct\MetricValue;

class CustomMetricProvider extends AbstractMetricProvider
{
    /**
     * @return array<Metric>
     */
    public function getMetrics(): array
    {
        // Simple metric with a single value
        return [
            $this->createGauge(
                'custom_metric',
                42.0,
                'Description of your custom metric',
                ['label1' => 'value1']
            ),
        ];
        
        // Or a more complex metric with multiple values
        /*
        $values = [
            new MetricValue(42.0, ['instance' => 'server1']),
            new MetricValue(24.0, ['instance' => 'server2']),
        ];
        
        return [
            $this->createMetric(
                'custom_multi_metric',
                $values,
                'Description of your custom multi-value metric',
                Metric::TYPE_GAUGE
            ),
        ];
        */
    }
}
```

Then register your service:

```xml
<service id="YourNamespace\Metrics\CustomMetricProvider">
    <tag name="shopware.prometheus.metrics"/>
</service>
```

## Accessing Metrics

The metrics are available at `https://your-shop.com/api/_internal/prometheus`. You can configure Prometheus to scrape this endpoint.

### Testing the Metrics Endpoint

You can test the metrics endpoint using the provided console command:

```bash
bin/console prometheus:test-metrics
```

This will display the metrics output that would be returned by the HTTP endpoint.

### Prometheus Configuration

Example Prometheus configuration:

```yaml
scrape_configs:
  - job_name: 'shopware'
    scrape_interval: 60s
    metrics_path: '/api/_internal/prometheus'
    static_configs:
      - targets: ['your-shop.com']
```