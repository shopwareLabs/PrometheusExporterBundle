<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Metrics;

use Doctrine\DBAL\Connection;
use Prometheus\CollectorRegistry;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler;

/**
 * Static facts about the scraped installation: environment, Shopware and database versions,
 * which subsystems are backed by Redis, and whether OpenSearch is in use.
 *
 * redis_usage reports all purposes it supports with 0/1 (rather than only active ones) so the
 * series set stays stable for dashboards and alert rules.
 *
 * The database version is queried last: if the database is unreachable during a scrape the
 * gauges registered before the failure are still rendered (the collector catches provider
 * exceptions and keeps partial samples).
 *
 * @internal
 */
class InstanceMetricProvider implements MetricProviderInterface
{
    /**
     * @param bool $openSearchEnabled the elasticsearch.enabled parameter; false when the Elasticsearch bundle is not installed (resolved in ScrapeProviderPass)
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly bool $openSearchEnabled,
        private readonly CacheItemPoolInterface $appCache,
        private readonly ?\SessionHandlerInterface $sessionHandler,
        private readonly string $appEnv,
        private readonly string $shopwareVersion,
        private readonly string $cartStorageType,
        private readonly string $numberRangeStorageType,
        private readonly bool $cacheInvalidationDelayEnabled,
        private readonly string $cacheInvalidationStorageType,
        private readonly ?string $nativeSessionSaveHandler = null,
    ) {
    }

    public function collect(CollectorRegistry $registry): void
    {
        $registry
            ->getOrRegisterGauge('', 'instance_info', 'Information about the Shopware installation', ['app_env', 'shopware_version'])
            ->set(1.0, [$this->appEnv, $this->shopwareVersion]);

        $usage = $registry->getOrRegisterGauge('', 'redis_usage', 'Whether a subsystem is backed by Redis (1) or by another storage (0)', ['purpose']);
        foreach ($this->redisUsage() as $purpose => $used) {
            $usage->set($used ? 1.0 : 0.0, [$purpose]);
        }

        $registry
            ->getOrRegisterGauge('', 'has_opensearch', 'Whether OpenSearch/Elasticsearch search is installed and enabled')
            ->set($this->openSearchEnabled ? 1.0 : 0.0);

        $this->collectDatabaseVersion($registry);
    }

    /**
     * @return array<string, bool>
     */
    private function redisUsage(): array
    {
        return [
            'app_cache' => $this->isRedisCache(),
            'session' => $this->isRedisSession(),
            'cart' => $this->cartStorageType === 'redis',
            'number_range' => $this->numberRangeStorageType === 'redis',
            'cache_invalidation' => $this->cacheInvalidationDelayEnabled && $this->cacheInvalidationStorageType === 'redis',
        ];
    }

    private function collectDatabaseVersion(CollectorRegistry $registry): void
    {
        $version = (string) $this->connection->fetchOne('SELECT VERSION()');

        $registry
            ->getOrRegisterGauge('', 'mysql_version_info', 'Database server version information', ['version', 'server'])
            ->set(1.0, [$version, \stripos($version, 'mariadb') !== false ? 'mariadb' : 'mysql']);
    }

    private function isRedisCache(): bool
    {
        $pool = $this->appCache;
        while ($pool instanceof TraceableAdapter) {
            $pool = $pool->getPool();
        }

        return $pool instanceof RedisAdapter || $pool instanceof RedisTagAwareAdapter;
    }

    private function isRedisSession(): bool
    {
        if ($this->sessionHandler instanceof RedisSessionHandler) {
            return true;
        }

        // native PHP session handler configured via php.ini (session.save_handler=redis, phpredis);
        // injectable for tests, read from the runtime configuration otherwise
        return ($this->nativeSessionSaveHandler ?? (string) \ini_get('session.save_handler')) === 'redis';
    }
}
