<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Metrics;

use Shopware\PrometheusExporter\Metrics\Struct\Metric;
use Shopware\PrometheusExporter\Metrics\Struct\MetricValue;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use OpenSearch\Client as OpenSearchClient;
use Psr\Container\ContainerInterface;

class OpenSearchMetricProvider extends AbstractMetricProvider
{
    /**
     * @param ContainerInterface $container Service locator to prevent errors when OpenSearch is not installed
     */
    public function __construct(
        #[Autowire(service: 'service_container')]
        private readonly ContainerInterface $container,
        private readonly HttpClientInterface $httpClient
    ) {
    }

    /**
     * @return array<Metric>
     */
    public function getMetrics(): array
    {
        // Check if OpenSearch is enabled
        if (!$this->isOpenSearchEnabled()) {
            return [];
        }

        try {
            $client = $this->getOpenSearchClient();
            if (!$client) {
                return [];
            }

            $metrics = [];
            
            // Get cluster health
            $clusterHealth = $client->cluster()->health();
            $clusterStatusMetric = $this->getClusterStatusMetric($clusterHealth);
            if ($clusterStatusMetric) {
                $metrics[] = $clusterStatusMetric;
            }

            // Get node stats
            $nodeStats = $client->nodes()->stats();
            $nodeMetrics = $this->getNodeMetrics($nodeStats);
            foreach ($nodeMetrics as $metric) {
                $metrics[] = $metric;
            }

            // Get indices stats
            $indicesStats = $client->indices()->stats();
            $indicesMetrics = $this->getIndicesMetrics($indicesStats);
            foreach ($indicesMetrics as $metric) {
                $metrics[] = $metric;
            }

            return $metrics;
        } catch (\Throwable $e) {
            // If we encounter any error, return empty metrics rather than breaking the endpoint
            return [];
        }
    }

    private function isOpenSearchEnabled(): bool
    {
        // Check if OpenSearch client service exists
        if (!$this->container->has('OpenSearch\\Client') && !$this->container->has('Elasticsearch\\Client')) {
            return false;
        }

        try {
            // Try to get configuration to see if it's enabled and reachable
            $config = [];
            if ($this->container->has('Shopware\\Elasticsearch\\Framework\\ElasticsearchHelper')) {
                $esHelper = $this->container->get('Shopware\\Elasticsearch\\Framework\\ElasticsearchHelper');
                if (method_exists($esHelper, 'isEnabled') && !$esHelper->isEnabled()) {
                    return false;
                }
            }
            
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    private function getOpenSearchClient(): ?OpenSearchClient
    {
        try {
            if ($this->container->has('OpenSearch\\Client')) {
                return $this->container->get('OpenSearch\\Client');
            }
            
            if ($this->container->has('Elasticsearch\\Client')) {
                return $this->container->get('Elasticsearch\\Client');
            }
            
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    private function getClusterStatusMetric(array $clusterHealth): ?Metric
    {
        if (!isset($clusterHealth['status'])) {
            return null;
        }
        
        // Map status to numeric value for easier monitoring
        // 0 = green, 1 = yellow, 2 = red
        $statusMap = [
            'green' => 0,
            'yellow' => 1,
            'red' => 2,
        ];
        
        $statusValue = $statusMap[$clusterHealth['status']] ?? 3; // Unknown status
        
        $values = [
            new MetricValue((float) $statusValue, ['status' => $clusterHealth['status'] ?? 'unknown']),
        ];
        
        // Create additional metric values for each status (1 for current status, 0 for others)
        foreach ($statusMap as $status => $value) {
            $values[] = new MetricValue(
                $status === $clusterHealth['status'] ? 1.0 : 0.0,
                ['state' => $status]
            );
        }
        
        return $this->createMetric(
            'opensearch_cluster_status',
            $values,
            'OpenSearch cluster status (0=green, 1=yellow, 2=red)',
            Metric::TYPE_GAUGE
        );
    }
    
    /**
     * @return array<Metric>
     */
    private function getNodeMetrics(array $nodeStats): array
    {
        $metrics = [];
        
        if (!isset($nodeStats['nodes']) || !is_array($nodeStats['nodes'])) {
            return $metrics;
        }
        
        $totalJvmMemoryUsed = 0;
        $totalJvmMemoryMax = 0;
        $totalDiskTotal = 0;
        $totalDiskUsed = 0;
        
        foreach ($nodeStats['nodes'] as $nodeId => $node) {
            // JVM memory usage
            if (isset($node['jvm']['mem'])) {
                $jvmMemoryUsed = $node['jvm']['mem']['heap_used_in_bytes'] ?? 0;
                $jvmMemoryMax = $node['jvm']['mem']['heap_max_in_bytes'] ?? 0;
                
                $totalJvmMemoryUsed += $jvmMemoryUsed;
                $totalJvmMemoryMax += $jvmMemoryMax;
                
                $metrics[] = $this->createGauge(
                    'opensearch_jvm_memory_used_bytes',
                    (float) $jvmMemoryUsed,
                    'JVM heap memory used in bytes',
                    ['node_id' => $nodeId, 'node_name' => $node['name'] ?? 'unknown']
                );
                
                $metrics[] = $this->createGauge(
                    'opensearch_jvm_memory_max_bytes',
                    (float) $jvmMemoryMax,
                    'JVM heap maximum memory in bytes',
                    ['node_id' => $nodeId, 'node_name' => $node['name'] ?? 'unknown']
                );
                
                if ($jvmMemoryMax > 0) {
                    $jvmMemoryPercentage = ($jvmMemoryUsed / $jvmMemoryMax) * 100;
                    $metrics[] = $this->createGauge(
                        'opensearch_jvm_memory_used_percentage',
                        $jvmMemoryPercentage,
                        'JVM heap memory used percentage',
                        ['node_id' => $nodeId, 'node_name' => $node['name'] ?? 'unknown']
                    );
                }
            }
            
            // Disk usage
            if (isset($node['fs']['total'])) {
                $diskTotal = $node['fs']['total']['total_in_bytes'] ?? 0;
                $diskFree = $node['fs']['total']['free_in_bytes'] ?? 0;
                $diskUsed = $diskTotal - $diskFree;
                
                $totalDiskTotal += $diskTotal;
                $totalDiskUsed += $diskUsed;
                
                $metrics[] = $this->createGauge(
                    'opensearch_disk_total_bytes',
                    (float) $diskTotal,
                    'Total disk space in bytes',
                    ['node_id' => $nodeId, 'node_name' => $node['name'] ?? 'unknown']
                );
                
                $metrics[] = $this->createGauge(
                    'opensearch_disk_used_bytes',
                    (float) $diskUsed,
                    'Used disk space in bytes',
                    ['node_id' => $nodeId, 'node_name' => $node['name'] ?? 'unknown']
                );
                
                if ($diskTotal > 0) {
                    $diskUsedPercentage = ($diskUsed / $diskTotal) * 100;
                    $metrics[] = $this->createGauge(
                        'opensearch_disk_used_percentage',
                        $diskUsedPercentage,
                        'Used disk space percentage',
                        ['node_id' => $nodeId, 'node_name' => $node['name'] ?? 'unknown']
                    );
                }
            }
        }
        
        // Add total metrics across all nodes
        if ($totalJvmMemoryMax > 0) {
            $metrics[] = $this->createGauge(
                'opensearch_jvm_memory_used_bytes_total',
                (float) $totalJvmMemoryUsed,
                'Total JVM heap memory used in bytes across all nodes'
            );
            
            $metrics[] = $this->createGauge(
                'opensearch_jvm_memory_max_bytes_total',
                (float) $totalJvmMemoryMax,
                'Total JVM heap maximum memory in bytes across all nodes'
            );
            
            $totalJvmMemoryPercentage = ($totalJvmMemoryUsed / $totalJvmMemoryMax) * 100;
            $metrics[] = $this->createGauge(
                'opensearch_jvm_memory_used_percentage_total',
                $totalJvmMemoryPercentage,
                'Total JVM heap memory used percentage across all nodes'
            );
        }
        
        if ($totalDiskTotal > 0) {
            $metrics[] = $this->createGauge(
                'opensearch_disk_total_bytes_total',
                (float) $totalDiskTotal,
                'Total disk space in bytes across all nodes'
            );
            
            $metrics[] = $this->createGauge(
                'opensearch_disk_used_bytes_total',
                (float) $totalDiskUsed,
                'Total used disk space in bytes across all nodes'
            );
            
            $totalDiskUsedPercentage = ($totalDiskUsed / $totalDiskTotal) * 100;
            $metrics[] = $this->createGauge(
                'opensearch_disk_used_percentage_total',
                $totalDiskUsedPercentage,
                'Total used disk space percentage across all nodes'
            );
        }
        
        return $metrics;
    }
    
    /**
     * @return array<Metric>
     */
    private function getIndicesMetrics(array $indicesStats): array
    {
        $metrics = [];
        
        if (!isset($indicesStats['indices']) || !is_array($indicesStats['indices'])) {
            return $metrics;
        }
        
        // Collect document counts for each index
        $docCountValues = [];
        $storeSizeValues = [];
        
        foreach ($indicesStats['indices'] as $indexName => $indexStats) {
            $docCount = $indexStats['primaries']['docs']['count'] ?? 0;
            $storeSize = $indexStats['primaries']['store']['size_in_bytes'] ?? 0;
            
            $docCountValues[] = new MetricValue(
                (float) $docCount,
                ['index' => $indexName]
            );
            
            $storeSizeValues[] = new MetricValue(
                (float) $storeSize,
                ['index' => $indexName]
            );
        }
        
        if (!empty($docCountValues)) {
            $metrics[] = $this->createMetric(
                'opensearch_index_documents_count',
                $docCountValues,
                'Number of documents in each OpenSearch index',
                Metric::TYPE_GAUGE
            );
        }
        
        if (!empty($storeSizeValues)) {
            $metrics[] = $this->createMetric(
                'opensearch_index_size_bytes',
                $storeSizeValues,
                'Size of each OpenSearch index in bytes',
                Metric::TYPE_GAUGE
            );
        }
        
        // Total metrics
        $totalDocs = $indicesStats['_all']['primaries']['docs']['count'] ?? 0;
        $totalSize = $indicesStats['_all']['primaries']['store']['size_in_bytes'] ?? 0;
        
        $metrics[] = $this->createGauge(
            'opensearch_documents_total',
            (float) $totalDocs,
            'Total number of documents across all indices'
        );
        
        $metrics[] = $this->createGauge(
            'opensearch_size_bytes_total',
            (float) $totalSize,
            'Total size of all indices in bytes'
        );
        
        // Count the total number of indices
        $indexCount = count($indicesStats['indices'] ?? []);
        $metrics[] = $this->createGauge(
            'opensearch_indices_count',
            (float) $indexCount,
            'Total number of indices'
        );
        
        return $metrics;
    }
}