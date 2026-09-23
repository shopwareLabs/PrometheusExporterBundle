<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Metrics;

use Prometheus\CollectorRegistry;

/**
 * @internal
 */
class PHPInfoMetricProvider implements MetricProviderInterface
{
    public function collect(CollectorRegistry $registry): void
    {
        $this->collectPhpVersion($registry);

        if (\function_exists('opcache_get_status') && \function_exists('opcache_get_configuration')) {
            $this->collectOpcache($registry);
        }
    }

    private function collectPhpVersion(CollectorRegistry $registry): void
    {
        $versionParts = \explode('.', \PHP_VERSION);

        $registry
            ->getOrRegisterGauge('', 'php_version_info', 'PHP version information', [
                'version', 'major', 'minor', 'patch', 'sapi', 'zts', 'debug',
            ])
            ->set(1.0, [
                \PHP_VERSION,
                $versionParts[0] ?? '0',
                $versionParts[1] ?? '0',
                $versionParts[2] ?? '0',
                \PHP_SAPI,
                \PHP_ZTS === 1 ? 'true' : 'false',
                \PHP_DEBUG === 1 ? 'true' : 'false',
            ]);
    }

    private function collectOpcache(CollectorRegistry $registry): void
    {
        $status = \opcache_get_status(false);
        $config = \opcache_get_configuration();

        if (!\is_array($status) || !\is_array($config)) {
            return;
        }

        $gauge = static function (string $name, string $help, float $value) use ($registry): void {
            $registry->getOrRegisterGauge('', $name, $help)->set($value);
        };

        $memoryUsage = $status['memory_usage'] ?? null;
        if (\is_array($memoryUsage)) {
            $gauge('opcache_memory_used_bytes', 'OPcache memory used in bytes', (float) ($memoryUsage['used_memory'] ?? 0));
            $gauge('opcache_memory_free_bytes', 'OPcache memory free in bytes', (float) ($memoryUsage['free_memory'] ?? 0));
            $gauge('opcache_memory_wasted_bytes', 'OPcache memory wasted in bytes', (float) ($memoryUsage['wasted_memory'] ?? 0));

            if (isset($memoryUsage['current_wasted_percentage'])) {
                $gauge('opcache_memory_wasted_percentage', 'OPcache memory wasted as percentage', (float) $memoryUsage['current_wasted_percentage']);
            }
        }

        $statistics = $status['opcache_statistics'] ?? null;
        if (\is_array($statistics)) {
            $gauge('opcache_hits', 'OPcache hits', (float) ($statistics['hits'] ?? 0));
            $gauge('opcache_misses', 'OPcache misses', (float) ($statistics['misses'] ?? 0));

            $lookups = ($statistics['hits'] ?? 0) + ($statistics['misses'] ?? 0);
            if ($lookups > 0) {
                $gauge('opcache_hit_rate_percentage', 'OPcache hit rate as percentage', $statistics['hits'] / $lookups * 100);
            }

            $gauge('opcache_scripts_count', 'Number of scripts cached in OPcache', (float) ($statistics['num_cached_scripts'] ?? 0));
            $gauge('opcache_keys_count', 'Number of keys cached in OPcache', (float) ($statistics['num_cached_keys'] ?? 0));
            $gauge('opcache_max_keys_count', 'Maximum number of keys that can be cached in OPcache', (float) ($statistics['max_cached_keys'] ?? 0));

            if (($statistics['max_cached_keys'] ?? 0) > 0) {
                $gauge('opcache_fullness_percentage', 'OPcache fullness as percentage (cached keys / max keys)', ($statistics['num_cached_keys'] ?? 0) / $statistics['max_cached_keys'] * 100);
            }

            $gauge('opcache_restarts_count', 'Number of out-of-memory restarts of OPcache', (float) ($statistics['oom_restarts'] ?? 0));
        }

        $directives = $config['directives'];
        $gauge('opcache_enabled', 'Flag indicating if OPcache is enabled', $directives['opcache.enable'] ? 1.0 : 0.0);
        $gauge('opcache_memory_size_bytes', 'OPcache memory size in bytes', (float) $directives['opcache.memory_consumption'] * 1024 * 1024);
        $gauge('opcache_max_accelerated_files', 'Maximum number of files that can be accelerated by OPcache', (float) $directives['opcache.max_accelerated_files']);
        $gauge('opcache_max_wasted_percentage', 'Maximum percentage of wasted memory before OPcache restarts', $directives['opcache.max_wasted_percentage']);
    }
}
