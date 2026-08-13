<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Storage;

use Predis\Client as PredisClient;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\AbstractRedis;
use Prometheus\Storage\Predis as PredisStorage;
use Prometheus\Storage\Redis as RedisStorage;
use Shopware\Core\Framework\Adapter\Cache\RedisConnectionFactory;
use Shopware\Core\Framework\Adapter\Redis\RedisConnectionProvider;
use Shopware\PrometheusExporter\PrometheusExporterException;

/**
 * @internal
 */
class RegistryFactory
{
    public function __construct(
        private readonly RedisConnectionProvider $connectionProvider,
        private readonly RedisConnectionFactory $connectionFactory,
        private readonly ?string $connectionName,
        private readonly ?string $dsn,
        private readonly string $keyPrefix,
    ) {
    }

    public function create(): CollectorRegistry
    {
        $connection = $this->resolveConnection();

        // Shared static across all promphp Redis-based adapters; there is only one registry per process.
        AbstractRedis::setPrefix($this->keyPrefix);

        $storage = match (true) {
            $connection instanceof \Redis => RedisStorage::fromExistingConnection($connection),
            $connection instanceof PredisClient => PredisStorage::fromExistingConnection($connection),
            default => throw PrometheusExporterException::unsupportedRedisClient($connection),
        };

        return new CollectorRegistry($storage, registerDefaultMetrics: false);
    }

    private function resolveConnection(): mixed
    {
        if ($this->dsn !== null) {
            return $this->connectionFactory->create($this->dsn);
        }

        if ($this->connectionName !== null) {
            return $this->connectionProvider->getConnection($this->connectionName);
        }

        throw PrometheusExporterException::storageNotConfigured();
    }
}
