<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Tests\Telemetry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Sample;
use Prometheus\Storage\InMemory;
use Shopware\Core\Framework\Telemetry\Metrics\Config\MetricConfig;
use Shopware\Core\Framework\Telemetry\Metrics\Metric\Metric;
use Shopware\Core\Framework\Telemetry\Metrics\Metric\Type;
use Shopware\PrometheusExporter\Telemetry\PrometheusTransport;

/**
 * @internal
 */
#[CoversClass(PrometheusTransport::class)]
class PrometheusTransportTest extends TestCase
{
    private CollectorRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new CollectorRegistry(new InMemory(), registerDefaultMetrics: false);
    }

    public function testCounterAccumulates(): void
    {
        $transport = $this->createTransport();

        $transport->emit($this->metric('order.placed.count', Type::COUNTER, 2));
        $transport->emit($this->metric('order.placed.count', Type::COUNTER, 3));

        static::assertSame(5.0, (float) $this->singleSample('order_placed_count')->getValue());
    }

    public function testGaugeLastWriteWins(): void
    {
        $transport = $this->createTransport();

        $transport->emit($this->metric('queue.depth', Type::GAUGE, 10));
        $transport->emit($this->metric('queue.depth', Type::GAUGE, 4));

        static::assertSame(4.0, (float) $this->singleSample('queue_depth')->getValue());
    }

    public function testUpDownCounterAccumulatesAsGauge(): void
    {
        $transport = $this->createTransport();

        $transport->emit($this->metric('sessions.active', Type::UPDOWN_COUNTER, 5));
        $transport->emit($this->metric('sessions.active', Type::UPDOWN_COUNTER, -2));

        $family = $this->family('sessions_active');
        static::assertSame('gauge', $family->getType());
        static::assertSame(3.0, (float) $family->getSamples()[0]->getValue());
    }

    public function testHistogramUsesConfiguredBuckets(): void
    {
        $metricConfig = new MetricConfig(
            name: 'cart.calculation.duration',
            description: 'Cart calculation duration',
            type: Type::HISTOGRAM,
            enabled: true,
            parameters: ['buckets' => [1, 5]],
        );
        $transport = $this->createTransport(metricConfigs: [$metricConfig->name => $metricConfig]);

        $transport->emit($this->metric('cart.calculation.duration', Type::HISTOGRAM, 3));

        $bucketSamples = \array_filter(
            $this->family('cart_calculation_duration')->getSamples(),
            static fn (Sample $sample): bool => \str_ends_with($sample->getName(), '_bucket'),
        );
        $les = \array_map(
            static fn (Sample $sample): string => $sample->getLabelValues()[0],
            \array_values($bucketSamples),
        );

        static::assertSame(['1', '5', '+Inf'], $les);
    }

    public function testBufferedModeWritesOnFlushOnly(): void
    {
        $transport = $this->createTransport(buffered: true);

        $transport->emit($this->metric('order.placed.count', Type::COUNTER, 2));
        static::assertSame([], $this->registry->getMetricFamilySamples());

        $transport->flush();
        static::assertSame(2.0, (float) $this->singleSample('order_placed_count')->getValue());

        // the buffer is cleared on flush, a second flush must not double-count
        $transport->flush();
        static::assertSame(2.0, (float) $this->singleSample('order_placed_count')->getValue());
    }

    public function testDirectModeWritesImmediately(): void
    {
        $transport = $this->createTransport(buffered: false);

        $transport->emit($this->metric('order.placed.count', Type::COUNTER, 2));

        static::assertSame(2.0, (float) $this->singleSample('order_placed_count')->getValue());
    }

    public function testNamespaceIsSanitizedAndPrefixed(): void
    {
        $transport = $this->createTransport(namespace: 'shopware');

        $transport->emit($this->metric('order.placed.count', Type::COUNTER, 1));

        static::assertSame(1.0, (float) $this->singleSample('shopware_order_placed_count')->getValue());
    }

    public function testLabelsAreSortedAndValuesNormalized(): void
    {
        $transport = $this->createTransport();

        $transport->emit($this->metric('mail.send.count', Type::COUNTER, 1, ['zeta' => true, 'alpha' => 7]));
        $transport->emit($this->metric('mail.send.count', Type::COUNTER, 1, ['alpha' => 7, 'zeta' => true]));

        static::assertSame(['alpha', 'zeta'], $this->family('mail_send_count')->getLabelNames());
        $sample = $this->singleSample('mail_send_count');
        static::assertSame(['7', 'true'], $sample->getLabelValues());
        static::assertSame(2.0, (float) $sample->getValue());
    }

    /**
     * @param array<string, MetricConfig> $metricConfigs
     */
    private function createTransport(
        bool $buffered = false,
        array $metricConfigs = [],
        ?string $namespace = null,
    ): PrometheusTransport {
        return new PrometheusTransport($this->registry, $metricConfigs, $namespace, $buffered);
    }

    /**
     * @param array<non-empty-string, string|bool|float|int> $labels
     */
    private function metric(string $name, Type $type, int|float $value, array $labels = []): Metric
    {
        return Metric::fromArray([
            'name' => $name,
            'type' => $type,
            'value' => $value,
            'labels' => $labels,
        ]);
    }

    private function family(string $name): \Prometheus\MetricFamilySamples
    {
        foreach ($this->registry->getMetricFamilySamples() as $family) {
            if ($family->getName() === $name) {
                return $family;
            }
        }

        static::fail(\sprintf('Metric family "%s" not found', $name));
    }

    private function singleSample(string $family): Sample
    {
        $samples = $this->family($family)->getSamples();
        static::assertCount(1, $samples);

        return $samples[0];
    }
}
