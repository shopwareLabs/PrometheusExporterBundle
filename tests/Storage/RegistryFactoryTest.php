<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\Predis as PredisStorage;
use Shopware\Core\Framework\Adapter\Cache\RedisConnectionFactory;
use Shopware\Core\Framework\Adapter\Redis\RedisConnectionProvider;
use Shopware\PrometheusExporter\PrometheusExporterException;
use Shopware\PrometheusExporter\Storage\RegistryFactory;

/**
 * @internal
 */
#[CoversClass(RegistryFactory::class)]
class RegistryFactoryTest extends TestCase
{
    public function testCreatesPredisStorageFromNamedConnection(): void
    {
        $provider = self::createStub(RedisConnectionProvider::class);
        $provider->method('getConnection')->willReturn(new Client());

        $factory = new RegistryFactory(
            $provider,
            self::createStub(RedisConnectionFactory::class),
            'telemetry',
            null,
            'sw:metrics',
        );

        static::assertInstanceOf(PredisStorage::class, $this->extractStorage($factory->create()));
    }

    public function testDsnTakesPriorityOverConnectionName(): void
    {
        // The named connection would fail with an unsupported client; only the DSN path succeeds.
        $provider = self::createStub(RedisConnectionProvider::class);
        $provider->method('getConnection')->willReturn(new \stdClass());

        $connectionFactory = self::createStub(RedisConnectionFactory::class);
        $connectionFactory->method('create')->willReturn(new Client());

        $factory = new RegistryFactory(
            $provider,
            $connectionFactory,
            'telemetry',
            'redis://localhost:6379/0',
            'sw:metrics',
        );

        static::assertInstanceOf(PredisStorage::class, $this->extractStorage($factory->create()));
    }

    public function testUnsupportedClientThrows(): void
    {
        $provider = self::createStub(RedisConnectionProvider::class);
        $provider->method('getConnection')->willReturn(new \stdClass());

        $factory = new RegistryFactory(
            $provider,
            self::createStub(RedisConnectionFactory::class),
            'telemetry',
            null,
            'sw:metrics',
        );

        $this->expectExceptionObject(PrometheusExporterException::unsupportedRedisClient(new \stdClass()));

        $factory->create();
    }

    public function testMissingStorageConfigurationThrows(): void
    {
        $factory = new RegistryFactory(
            self::createStub(RedisConnectionProvider::class),
            self::createStub(RedisConnectionFactory::class),
            null,
            null,
            'sw:metrics',
        );

        $this->expectExceptionObject(PrometheusExporterException::storageNotConfigured());

        $factory->create();
    }

    private function extractStorage(CollectorRegistry $registry): Adapter
    {
        $storage = (new \ReflectionProperty(CollectorRegistry::class, 'storageAdapter'))->getValue($registry);
        static::assertInstanceOf(Adapter::class, $storage);

        return $storage;
    }
}
