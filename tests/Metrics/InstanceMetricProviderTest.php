<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Tests\Metrics;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;
use Psr\Cache\CacheItemPoolInterface;
use Shopware\PrometheusExporter\Metrics\InstanceMetricProvider;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler;

/**
 * @internal
 */
#[CoversClass(InstanceMetricProvider::class)]
class InstanceMetricProviderTest extends TestCase
{
    private CollectorRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new CollectorRegistry(new InMemory(), registerDefaultMetrics: false);
    }

    public function testCollectsInstanceFacts(): void
    {
        $this->createProvider(databaseVersion: '8.0.36')->collect($this->registry);

        $output = $this->render();

        static::assertStringContainsString('instance_info{app_env="prod",shopware_version="6.7.12.0"} 1', $output);
        static::assertStringContainsString('redis_usage{purpose="app_cache"} 0', $output);
        static::assertStringContainsString('redis_usage{purpose="session"} 0', $output);
        static::assertStringContainsString('redis_usage{purpose="cart"} 0', $output);
        static::assertStringContainsString('redis_usage{purpose="number_range"} 0', $output);
        static::assertStringContainsString('redis_usage{purpose="cache_invalidation"} 0', $output);
        static::assertStringContainsString('has_opensearch 0', $output);
        static::assertStringContainsString('mysql_version_info{version="8.0.36",server="mysql"} 1', $output);
    }

    public function testDetectsMariaDb(): void
    {
        $this->createProvider(databaseVersion: '10.11.6-MariaDB-log')->collect($this->registry);

        static::assertStringContainsString(
            'mysql_version_info{version="10.11.6-MariaDB-log",server="mariadb"} 1',
            $this->render(),
        );
    }

    public function testDetectsRedisAppCacheBehindTraceableDecorator(): void
    {
        $appCache = new TraceableAdapter(self::createStub(RedisAdapter::class));

        $this->createProvider(appCache: $appCache)->collect($this->registry);

        static::assertStringContainsString('redis_usage{purpose="app_cache"} 1', $this->render());
    }

    public function testDetectsRedisSessionHandler(): void
    {
        $this->createProvider(sessionHandler: self::createStub(RedisSessionHandler::class))->collect($this->registry);

        static::assertStringContainsString('redis_usage{purpose="session"} 1', $this->render());
    }

    public function testDetectsNativeRedisSessionHandler(): void
    {
        $this->createProvider(nativeSessionSaveHandler: 'redis')->collect($this->registry);

        static::assertStringContainsString('redis_usage{purpose="session"} 1', $this->render());
    }

    public function testDetectsRedisBackedStorages(): void
    {
        $this->createProvider(
            cartStorageType: 'redis',
            numberRangeStorageType: 'redis',
            cacheInvalidationStorageType: 'redis',
        )->collect($this->registry);

        $output = $this->render();

        static::assertStringContainsString('redis_usage{purpose="cart"} 1', $output);
        static::assertStringContainsString('redis_usage{purpose="number_range"} 1', $output);
        static::assertStringContainsString('redis_usage{purpose="cache_invalidation"} 1', $output);
    }

    public function testDisabledInvalidationDelayDoesNotCountAsRedisUsage(): void
    {
        $this->createProvider(
            cacheInvalidationDelayEnabled: false,
            cacheInvalidationStorageType: 'redis',
        )->collect($this->registry);

        static::assertStringContainsString('redis_usage{purpose="cache_invalidation"} 0', $this->render());
    }

    public function testDetectsEnabledOpenSearch(): void
    {
        $this->createProvider(openSearchEnabled: true)->collect($this->registry);

        static::assertStringContainsString('has_opensearch 1', $this->render());
    }

    public function testInfrastructureGaugesSurviveAnUnreachableDatabase(): void
    {
        $connection = self::createStub(Connection::class);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('connection refused'));

        $provider = $this->createProvider(connection: $connection);

        try {
            $provider->collect($this->registry);
            static::fail('the database failure must propagate to the collector');
        } catch (\RuntimeException) {
        }

        $output = $this->render();
        static::assertStringContainsString('instance_info', $output);
        static::assertStringContainsString('redis_usage', $output);
        static::assertStringNotContainsString('mysql_version_info', $output);
    }

    private function createProvider(
        ?Connection $connection = null,
        bool $openSearchEnabled = false,
        ?CacheItemPoolInterface $appCache = null,
        ?\SessionHandlerInterface $sessionHandler = null,
        string $databaseVersion = '8.0.36',
        string $cartStorageType = 'mysql',
        string $numberRangeStorageType = 'mysql',
        bool $cacheInvalidationDelayEnabled = true,
        string $cacheInvalidationStorageType = 'mysql',
        string $nativeSessionSaveHandler = 'files',
    ): InstanceMetricProvider {
        if ($connection === null) {
            $connection = self::createStub(Connection::class);
            $connection->method('fetchOne')->willReturn($databaseVersion);
        }

        return new InstanceMetricProvider(
            $connection,
            $openSearchEnabled,
            $appCache ?? new ArrayAdapter(),
            $sessionHandler,
            'prod',
            '6.7.12.0',
            $cartStorageType,
            $numberRangeStorageType,
            $cacheInvalidationDelayEnabled,
            $cacheInvalidationStorageType,
            $nativeSessionSaveHandler,
        );
    }

    private function render(): string
    {
        return (new RenderTextFormat())->render(\array_values($this->registry->getMetricFamilySamples()));
    }
}
