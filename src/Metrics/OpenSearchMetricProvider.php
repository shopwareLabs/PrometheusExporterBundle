<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Metrics;

use OpenSearch\Client as OpenSearchClient;
use Prometheus\CollectorRegistry;
use Psr\Container\ContainerInterface;

/**
 * Generic cluster statistics; prefer a dedicated infrastructure exporter
 * (e.g. elasticsearch_exporter) when you can run one next to the cluster.
 *
 * @internal
 */
class OpenSearchMetricProvider implements MetricProviderInterface
{
    private const NODE_LABELS = ['node_id', 'node_name'];

    /**
     * @param ContainerInterface $container service locator, so the provider degrades gracefully when no search client is installed
     */
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function collect(CollectorRegistry $registry): void
    {
        if (!$this->isOpenSearchEnabled()) {
            return;
        }

        $client = $this->getOpenSearchClient();
        if ($client === null) {
            return;
        }

        $this->collectClusterHealth($registry, $client->cluster()->health());
        $this->collectNodeStats($registry, $client->nodes()->stats());
        $this->collectIndicesStats($registry, $client->indices()->stats());
    }

    private function isOpenSearchEnabled(): bool
    {
        if (!$this->container->has('OpenSearch\Client') && !$this->container->has('Elasticsearch\Client')) {
            return false;
        }

        if ($this->container->has('Shopware\Elasticsearch\Framework\ElasticsearchHelper')) {
            $esHelper = $this->container->get('Shopware\Elasticsearch\Framework\ElasticsearchHelper');
            if (\is_object($esHelper) && \method_exists($esHelper, 'isEnabled') && !$esHelper->isEnabled()) {
                return false;
            }
        }

        return true;
    }

    private function getOpenSearchClient(): ?OpenSearchClient
    {
        foreach (['OpenSearch\Client', 'Elasticsearch\Client'] as $serviceId) {
            if ($this->container->has($serviceId)) {
                $client = $this->container->get($serviceId);

                return $client instanceof OpenSearchClient ? $client : null;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $clusterHealth
     */
    private function collectClusterHealth(CollectorRegistry $registry, array $clusterHealth): void
    {
        if (!isset($clusterHealth['status'])) {
            return;
        }

        // 0 = green, 1 = yellow, 2 = red, 3 = unknown
        $statusMap = ['green' => 0, 'yellow' => 1, 'red' => 2];

        $registry
            ->getOrRegisterGauge('', 'opensearch_cluster_status', 'OpenSearch cluster status (0=green, 1=yellow, 2=red, 3=unknown)')
            ->set((float) ($statusMap[$clusterHealth['status']] ?? 3));

        $stateGauge = $registry->getOrRegisterGauge(
            '',
            'opensearch_cluster_status_state',
            'One time series per state; 1 for the current cluster state, 0 otherwise',
            ['state'],
        );
        foreach (\array_keys($statusMap) as $state) {
            $stateGauge->set($state === $clusterHealth['status'] ? 1.0 : 0.0, [$state]);
        }
    }

    /**
     * @param array<string, mixed> $nodeStats
     */
    private function collectNodeStats(CollectorRegistry $registry, array $nodeStats): void
    {
        if (!isset($nodeStats['nodes']) || !\is_array($nodeStats['nodes'])) {
            return;
        }

        $totalJvmMemoryUsed = 0.0;
        $totalJvmMemoryMax = 0.0;
        $totalDiskTotal = 0.0;
        $totalDiskUsed = 0.0;

        $nodeGauge = static function (string $name, string $help, float $value, string $nodeId, string $nodeName) use ($registry): void {
            $registry->getOrRegisterGauge('', $name, $help, self::NODE_LABELS)->set($value, [$nodeId, $nodeName]);
        };

        foreach ($nodeStats['nodes'] as $nodeId => $node) {
            $nodeId = (string) $nodeId;
            $nodeName = (string) ($node['name'] ?? 'unknown');

            if (isset($node['jvm']['mem'])) {
                $jvmMemoryUsed = (float) ($node['jvm']['mem']['heap_used_in_bytes'] ?? 0);
                $jvmMemoryMax = (float) ($node['jvm']['mem']['heap_max_in_bytes'] ?? 0);

                $totalJvmMemoryUsed += $jvmMemoryUsed;
                $totalJvmMemoryMax += $jvmMemoryMax;

                $nodeGauge('opensearch_jvm_memory_used_bytes', 'JVM heap memory used in bytes', $jvmMemoryUsed, $nodeId, $nodeName);
                $nodeGauge('opensearch_jvm_memory_max_bytes', 'JVM heap maximum memory in bytes', $jvmMemoryMax, $nodeId, $nodeName);

                if ($jvmMemoryMax > 0) {
                    $nodeGauge('opensearch_jvm_memory_used_percentage', 'JVM heap memory used percentage', $jvmMemoryUsed / $jvmMemoryMax * 100, $nodeId, $nodeName);
                }
            }

            if (isset($node['fs']['total'])) {
                $diskTotal = (float) ($node['fs']['total']['total_in_bytes'] ?? 0);
                $diskUsed = $diskTotal - (float) ($node['fs']['total']['free_in_bytes'] ?? 0);

                $totalDiskTotal += $diskTotal;
                $totalDiskUsed += $diskUsed;

                $nodeGauge('opensearch_disk_total_bytes', 'Total disk space in bytes', $diskTotal, $nodeId, $nodeName);
                $nodeGauge('opensearch_disk_used_bytes', 'Used disk space in bytes', $diskUsed, $nodeId, $nodeName);

                if ($diskTotal > 0) {
                    $nodeGauge('opensearch_disk_used_percentage', 'Used disk space percentage', $diskUsed / $diskTotal * 100, $nodeId, $nodeName);
                }
            }
        }

        $gauge = static function (string $name, string $help, float $value) use ($registry): void {
            $registry->getOrRegisterGauge('', $name, $help)->set($value);
        };

        if ($totalJvmMemoryMax > 0) {
            $gauge('opensearch_jvm_memory_used_bytes_total', 'Total JVM heap memory used in bytes across all nodes', $totalJvmMemoryUsed);
            $gauge('opensearch_jvm_memory_max_bytes_total', 'Total JVM heap maximum memory in bytes across all nodes', $totalJvmMemoryMax);
            $gauge('opensearch_jvm_memory_used_percentage_total', 'Total JVM heap memory used percentage across all nodes', $totalJvmMemoryUsed / $totalJvmMemoryMax * 100);
        }

        if ($totalDiskTotal > 0) {
            $gauge('opensearch_disk_total_bytes_total', 'Total disk space in bytes across all nodes', $totalDiskTotal);
            $gauge('opensearch_disk_used_bytes_total', 'Total used disk space in bytes across all nodes', $totalDiskUsed);
            $gauge('opensearch_disk_used_percentage_total', 'Total used disk space percentage across all nodes', $totalDiskUsed / $totalDiskTotal * 100);
        }
    }

    /**
     * @param array<string, mixed> $indicesStats
     */
    private function collectIndicesStats(CollectorRegistry $registry, array $indicesStats): void
    {
        if (!isset($indicesStats['indices']) || !\is_array($indicesStats['indices'])) {
            return;
        }

        $docCountGauge = $registry->getOrRegisterGauge('', 'opensearch_index_documents_count', 'Number of documents in each OpenSearch index', ['index']);
        $storeSizeGauge = $registry->getOrRegisterGauge('', 'opensearch_index_size_bytes', 'Size of each OpenSearch index in bytes', ['index']);

        foreach ($indicesStats['indices'] as $indexName => $indexStats) {
            $docCountGauge->set((float) ($indexStats['primaries']['docs']['count'] ?? 0), [(string) $indexName]);
            $storeSizeGauge->set((float) ($indexStats['primaries']['store']['size_in_bytes'] ?? 0), [(string) $indexName]);
        }

        $gauge = static function (string $name, string $help, float $value) use ($registry): void {
            $registry->getOrRegisterGauge('', $name, $help)->set($value);
        };

        $gauge('opensearch_documents_total', 'Total number of documents across all indices', (float) ($indicesStats['_all']['primaries']['docs']['count'] ?? 0));
        $gauge('opensearch_size_bytes_total', 'Total size of all indices in bytes', (float) ($indicesStats['_all']['primaries']['store']['size_in_bytes'] ?? 0));
        $gauge('opensearch_indices_count', 'Total number of indices', (float) \count($indicesStats['indices']));
    }
}
