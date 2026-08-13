<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Metrics;

use Shopware\PrometheusExporter\Metrics\Struct\Metric;
use Shopware\PrometheusExporter\Metrics\Struct\MetricValue;

/**
 * @internal
 */
class PHPInfoMetricProvider extends AbstractMetricProvider
{
    /**
     * @return array<Metric>
     */
    public function getMetrics(): array
    {
        $metrics = [];
        
        // Add PHP version info
        $metrics[] = $this->getPhpVersionMetric();
        
        // Add OPcache metrics if available
        if (function_exists('opcache_get_status') && function_exists('opcache_get_configuration')) {
            $opcacheMetrics = $this->getOpcacheMetrics();
            foreach ($opcacheMetrics as $metric) {
                $metrics[] = $metric;
            }
        }
        
        return $metrics;
    }
    
    private function getPhpVersionMetric(): Metric
    {
        $versionParts = explode('.', PHP_VERSION);
        $versionMajor = (int) ($versionParts[0] ?? 0);
        $versionMinor = (int) ($versionParts[1] ?? 0);
        $versionPatch = (int) ($versionParts[2] ?? 0);
        
        return $this->createGauge(
            'php_version_info',
            1.0, // Use 1.0 as a constant value since this is an info metric
            'PHP version information',
            [
                'version' => PHP_VERSION,
                'major' => $versionMajor,
                'minor' => $versionMinor,
                'patch' => $versionPatch,
                'sapi' => PHP_SAPI,
                'zts' => PHP_ZTS ? 'true' : 'false',
                'debug' => PHP_DEBUG ? 'true' : 'false',
            ]
        );
    }
    
    /**
     * @return array<Metric>
     */
    private function getOpcacheMetrics(): array
    {
        $metrics = [];
        
        try {
            $status = opcache_get_status(false);
            $config = opcache_get_configuration();
            
            if (!is_array($status) || !is_array($config)) {
                return [];
            }
            
            // OPcache memory usage
            $memoryUsage = $status['memory_usage'] ?? [];
            if (is_array($memoryUsage)) {
                $metrics[] = $this->createGauge(
                    'opcache_memory_used_bytes',
                    (float) ($memoryUsage['used_memory'] ?? 0),
                    'OPcache memory used in bytes'
                );
                
                $metrics[] = $this->createGauge(
                    'opcache_memory_free_bytes',
                    (float) ($memoryUsage['free_memory'] ?? 0),
                    'OPcache memory free in bytes'
                );
                
                $metrics[] = $this->createGauge(
                    'opcache_memory_wasted_bytes',
                    (float) ($memoryUsage['wasted_memory'] ?? 0),
                    'OPcache memory wasted in bytes'
                );
                
                if (isset($memoryUsage['current_wasted_percentage'])) {
                    $metrics[] = $this->createGauge(
                        'opcache_memory_wasted_percentage',
                        (float) $memoryUsage['current_wasted_percentage'],
                        'OPcache memory wasted as percentage'
                    );
                }
            }
            
            // OPcache statistics
            $statistics = $status['opcache_statistics'] ?? [];
            if (is_array($statistics)) {
                $metrics[] = $this->createGauge(
                    'opcache_hits',
                    (float) ($statistics['hits'] ?? 0),
                    'OPcache hits'
                );
                
                $metrics[] = $this->createGauge(
                    'opcache_misses',
                    (float) ($statistics['misses'] ?? 0),
                    'OPcache misses'
                );
                
                if (isset($statistics['hits'], $statistics['misses']) 
                    && ($statistics['hits'] + $statistics['misses']) > 0) {
                    $hitRate = $statistics['hits'] / ($statistics['hits'] + $statistics['misses']) * 100;
                    $metrics[] = $this->createGauge(
                        'opcache_hit_rate_percentage',
                        $hitRate,
                        'OPcache hit rate as percentage'
                    );
                }
                
                $metrics[] = $this->createGauge(
                    'opcache_scripts_count',
                    (float) ($statistics['num_cached_scripts'] ?? 0),
                    'Number of scripts cached in OPcache'
                );
                
                $metrics[] = $this->createGauge(
                    'opcache_keys_count',
                    (float) ($statistics['num_cached_keys'] ?? 0),
                    'Number of keys cached in OPcache'
                );
                
                $metrics[] = $this->createGauge(
                    'opcache_max_keys_count',
                    (float) ($statistics['max_cached_keys'] ?? 0),
                    'Maximum number of keys that can be cached in OPcache'
                );
                
                // Calculate OPcache fullness percentage
                if (isset($statistics['num_cached_keys'], $statistics['max_cached_keys']) 
                    && $statistics['max_cached_keys'] > 0) {
                    $fullnessPercentage = ($statistics['num_cached_keys'] / $statistics['max_cached_keys']) * 100;
                    $metrics[] = $this->createGauge(
                        'opcache_fullness_percentage',
                        $fullnessPercentage,
                        'OPcache fullness as percentage (cached keys / max keys)'
                    );
                }
                
                $metrics[] = $this->createGauge(
                    'opcache_restarts_count',
                    (float) ($statistics['oom_restarts'] ?? 0),
                    'Number of out-of-memory restarts of OPcache'
                );
            }
            
            // OPcache configuration
            if (isset($config['directives'])) {
                $enabled = (int) ($config['directives']['opcache.enable'] ?? 0);
                $metrics[] = $this->createGauge(
                    'opcache_enabled',
                    (float) $enabled,
                    'Flag indicating if OPcache is enabled'
                );
                
                // Memory configuration
                $metrics[] = $this->createGauge(
                    'opcache_memory_size_bytes',
                    (float) ($config['directives']['opcache.memory_consumption'] ?? 0) * 1024 * 1024,
                    'OPcache memory size in bytes'
                );
                
                $metrics[] = $this->createGauge(
                    'opcache_max_accelerated_files',
                    (float) ($config['directives']['opcache.max_accelerated_files'] ?? 0),
                    'Maximum number of files that can be accelerated by OPcache'
                );
                
                $metrics[] = $this->createGauge(
                    'opcache_max_wasted_percentage',
                    (float) ($config['directives']['opcache.max_wasted_percentage'] ?? 0),
                    'Maximum percentage of wasted memory before OPcache restarts'
                );
            }
        } catch (\Throwable $e) {
            // In case of any error accessing OPcache status, return empty metrics
            return [];
        }
        
        return $metrics;
    }
}